<?php
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\UserInfo;

global $wgHooks;
$wgHooks["PersonalUrls"][] = "AuthAzureEasyAuth::NoLoginLinkOnMainPage";
$wgHooks["UserLogout"  ][] = "AuthAzureEasyAuth::onUserLogout";

class AuthAzureEasyAuth extends MediaWiki\Session\ImmutableSessionProviderWithCookie
{
    //TODO: Single Sign Out - endsession endpoint by checking if action=AuthAzureEasyAuth-EndSession
    private $principal = null;
    
    /**
     * @param array $params Keys include:
     *  - priority: (required) Set the priority
     *  - sessionCookieName: Session cookie name. Default is '_AuthRemoteuserSession'.
     *  - sessionCookieOptions: Options to pass to WebResponse::setCookie().
     */
    public function __construct(array $params = [])
    {
        if (!isset($params['sessionCookieName'])) {
            $params['sessionCookieName'] = '_AuthAzureEasyAuth';
        }
        parent::__construct($params);

        if (!isset( $params['priority'] )) {
            throw new \InvalidArgumentException(__METHOD__ . ': priority must be specified');
        }
        if ($params['priority'] < SessionInfo::MIN_PRIORITY || $params['priority'] > SessionInfo::MAX_PRIORITY) {
            throw new \InvalidArgumentException(__METHOD__ . ': Invalid priority');
        }

        $this->priority = $params['priority'];
        
        $principalValue = isset($_SERVER["MS_CLIENT_PRINCIPAL"]) ? $_SERVER["MS_CLIENT_PRINCIPAL"] : $_SERVER["HTTP_X_MS_CLIENT_PRINCIPAL"];

        $this->principal = json_decode(base64_decode($principalValue));
    }
    
    public function provideSessionInfo(WebRequest $request)
    {
        $this->denyNonWhitelistedTenants();

        $id = $this->getSessionIdFromCookie($request);
        
        if ((null === $id)||(!MediaWiki\Session\SessionManager::singleton()->getSessionById($id))) {
            $username = $this->getUpn();
            $sessionInfo = $this->newSessionForRequest($username, $request);

            return $sessionInfo;
        }

        $sessionInfo = new SessionInfo($this->priority, [
            'provider' => $this,
            'id' => $id,
            'persisted' => true
        ]);

        return $sessionInfo;
    }
    
    public function newSessionInfo($id = null)
    {
        return null;
    }

    /**
     * @param $username
     * @param WebRequest $request
     * @return SessionInfo
     */
    protected function newSessionForRequest($username, WebRequest $request)
    {
        $id = $this->getSessionIdFromCookie($request);

        $user = User::newFromName($username, 'usable');
        if (!$user) {
            throw new \InvalidArgumentException('Invalid user name');
        }

        $this->initUser($user);

        $info = new SessionInfo(SessionInfo::MAX_PRIORITY, [
            'provider' => $this,
            'id' => $id,
            'userInfo' => UserInfo::newFromUser($user, true),
            'persisted' => false
        ]);
        $session = $this->getManager()->getSessionFromInfo($info, $request);
        $session->persist();

        return $info;
    }

    protected function initUser(&$user)
    {
        if (Hooks::run("AuthAzureEasyAuthInitUser", array($user, true))) {
            // Check if above hook or some other effect (e.g.: https://phabricator.wikimedia.org/T95839 )
            // already created a user in the db. If so, reuse that one.
            $userFromDb = $user->getInstanceForUpdate();
            if (null !== $userFromDb) {
                $user = $user->getInstanceForUpdate();
            }

            $user->setRealName($this->getName());

            $user->setEmail($this->getUpn());

            $user->mEmailAuthenticated = wfTimestampNow();
            $user->setToken();
            
            // $user->setOption('enotifwatchlistpages', 1);
            // $user->setOption('enotifusertalkpages', 1);
            // $user->setOption('enotifminoredits', 1);
            // $user->setOption('enotifrevealaddr', 1);
        }

        $user->saveSettings();
    }

    private function denyNonWhitelistedTenants() {
        global $wgAuthAzureEasyAuthIssuers;
        
        $issuer = $this->getClaim("iss");
        if (!in_array($issuer, $wgAuthAzureEasyAuthIssuers, true)) {
            $username = $this->getUpn();
            echo "You are not allowed to access this site with account [ $username ]. Issuer was [ $issuer ].";
            die;
        }
    }

    private function getClaim($claimName)
    {
        $value = null;

        foreach ($this->principal->claims as $claim) {
            if ($claim->typ == $claimName) {
                $value = $claim->val;
                break;
            }
        }

        return $value;
    }

    private function getName()
    {
        return $this->getClaim("name");
    }

    private function getUpn()
    {
        return $this->getClaim("http://schemas.xmlsoap.org/ws/2005/05/identity/claims/upn");
    }

    public function canChangeUser()
    {
        return true;
    }

    /**
     * Removes the Login link from all pages.
     * https://www.mediawiki.org/wiki/Manual:Preventing_access#Removing_the_Login_link_from_all_pages
     */
    public static function NoLoginLinkOnMainPage(&$personal_urls)
    {
        unset( $personal_urls['login'] );
        unset( $personal_urls['anonlogin'] );
        return true;
    }

    // Inspired by https://www.mediawiki.org/wiki/Extension:RedirectAfterLogout
    public static function onUserLogout(&$user)
    {
        global $wgOut;
        $wgOut->redirect( "/.auth/logout" );
        
        return true;
    }
}
