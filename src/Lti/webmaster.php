<?php namespace Lti\Google;

use Google_Client;
use Google_Service_Webmasters;


class Google_Helper
{

    /**
     * @var Google_Client
     */
    private $client;
    /**
     * @var \Lti\Google\Google_Helper_Site
     */
    private $site;
    /**
     * @var \Lti\Google\Google_Helper_Sitemap
     */
    private $sitemap;
    /**
     * @var \Lti\Google\Google_Helper_Webmaster
     */
    private $webmaster;

    private $access_token;

    private $is_authenticated;

    public static $admin_permission_levels = array( 'siteFullUser', 'siteOwner' );

    public function __construct( $scopes, $application_name )
    {
        $this->client = $this->initialize_google_client( $scopes, $application_name );
    }

    private function initialize_google_client( $scopes, $application_name )
    {
        $client = new Google_Client();
        $client->setClientId( '384177546309-l0qgbfi3v9695nd0tonpu95310qhkmgf.apps.googleusercontent.com' );
        $client->setClientSecret( 'wjHfFujvQauLYUvNzLYi-ZO1' );
        $client->setScopes( $scopes );
        $client->setRedirectUri( 'urn:ietf:wg:oauth:2.0:oob' );
        $client->setAccessType( 'offline' );
        $client->setDeveloperKey( 'AIzaSyAApoI_39_L7J1PXseCRD27NM0gQozmrzA' );
        $client->setApplicationName( $application_name );

        return $client;
    }

    public function get_authentication_url()
    {
        //Hardening our client a bit by setting a per request state
        $this->client->setState( mt_rand() );

        return $this->client->createAuthUrl();
    }

    public function set_access_token( $access_token )
    {
        $this->access_token = $access_token;
        $this->client->setAccessToken( $access_token );
        $this->is_authenticated = true;
    }

    public function get_access_token()
    {
        return $this->access_token;
    }

    public function authenticate( $authentication_key )
    {
        $this->client->authenticate( $authentication_key );
        $this->set_access_token( $this->client->getAccessToken() );
        $this->is_authenticated = true;

        return $this->access_token;
    }

    public function is_authenticated()
    {
        return ( $this->is_authenticated === true );
    }

    public function assess_token_validity()
    {
        if ($this->client->isAccessTokenExpired()) {
            try {
                $this->client->refreshToken( $this->client->getRefreshToken() );
            } catch ( \Google_Auth_Exception $e ) {
                $this->is_authenticated = false;

                return false;
            }
        }

        return true;
    }

    public function revoke_token()
    {
        $this->client->revokeToken();
        $this->is_authenticated = false;
    }

    public function init_sitemap_service( $site_url, $sitemap_url )
    {
        $this->sitemap = new Google_Helper_Sitemap( $this->client, $site_url, $sitemap_url );
    }

    public function init_site_service( $site_url )
    {
        $this->site = new Google_Helper_Site( $this->client, $site_url );
    }

    public function get_site_service()
    {
        return $this->site;
    }

    public function get_sitemap_service()
    {
        return $this->sitemap;
    }

    public static function get_site_console_url( $site_url, $language = 'en' )
    {
        return sprintf( 'https://www.google.com/webmasters/tools/dashboard?hl=%s&siteUrl=%s', $language, $site_url );
    }

    public static function get_sitemap_console_url( $site_url, $language = 'en' )
    {
        return sprintf( 'https://www.google.com/webmasters/tools/sitemap-list?hl=%s&siteUrl=%s', $site_url, $language );
    }

}

class Google_Helper_Webmaster extends Google_Service_Webmasters
{


    protected $permissionLevel;
    protected $is_site_admin = false;
    protected $is_site_unverified_user = true;

    /**
     * @var \Google_Service_Webmasters_Sites_Resource
     */
    public $sites;

    public function __construct( Google_Client $client, $site_url )
    {
        parent::__construct( $client );
        $this->site_url = $site_url;
    }

    public function request_site_info()
    {
        /**
         * @var \Google_Service_Webmasters_WmxSite $site
         */
        $site                  = $this->sites->get( $this->site_url );
        $this->permissionLevel = $site->permissionLevel;
        if (in_array( $this->permissionLevel, Google_Helper::$admin_permission_levels )) {
            $this->is_site_admin           = true;
            $this->is_site_unverified_user = false;
        } elseif ($this->permissionLevel != 'siteUnverifiedUser') {
            $this->is_site_unverified_user = false;
        }

        return true;
    }

    public function is_site_admin()
    {
        return $this->is_site_admin;
    }

    public function is_site_unverified_user()
    {
        return $this->is_site_unverified_user;
    }


}

class Google_Helper_Sitemap extends Google_Helper_Webmaster
{
    private $last_submitted;
    private $is_pending;
    private $last_downloaded;
    private $nb_pages_submitted;
    private $nb_pages_indexed;
    private $has_sitemap = false;
    private $sitemap_url;

    public function __construct( Google_Client $client, $site_url, $sitemap_url )
    {
        parent::__construct( $client, $site_url );
        $this->sitemap_url = $sitemap_url;
    }

    function request_sitemap_info()
    {
        /**
         * @var \Google_Service_Webmasters_SitemapsListResponse $sitemap
         */
        $sitemap = $this->sitemaps->listSitemaps( $this->site_url );

        /**
         * @var \Google_Service_Webmasters_WmxSitemap $sitemaps
         */
        $sitemaps = $sitemap->getSitemap();
        /**
         * @var \Google_Service_Webmasters_WmxSitemap $sitemap
         */
        foreach ($sitemaps as $sitemap) {
            if ($sitemap['path'] == $this->sitemap_url) {
                $this->has_sitemap     = true;
                $this->last_submitted  = $sitemap['lastSubmitted'];
                $this->is_pending      = $sitemap['isPending'] === true;
                $this->last_downloaded = $sitemap['lastDownloaded'];
                $tmp                   = $sitemap->getContents();
                if (count( $tmp ) > 0) {
                    $this->nb_pages_submitted = $tmp[0]['submitted'];
                    $this->nb_pages_indexed   = $tmp[0]['indexed'];
                }
            }
            break;
        }
    }

    function submit_sitemap()
    {
        $this->sitemaps->submit( $this->site_url, $this->sitemap_url );
    }

    function delete_sitemap()
    {
        $this->sitemaps->delete( $this->site_url, $this->sitemap_url );
    }

    /**
     * @return mixed
     */
    public function getLastSubmitted()
    {
        return $this->last_submitted;
    }

    /**
     * @return mixed
     */
    public function getIsPending()
    {
        return $this->is_pending;
    }

    /**
     * @return mixed
     */
    public function getLastDownloaded()
    {
        return $this->last_downloaded;
    }

    /**
     * @return mixed
     */
    public function getNbPagesSubmitted()
    {
        return $this->nb_pages_submitted;
    }

    /**
     * @return mixed
     */
    public function getNbPagesIndexed()
    {
        return $this->nb_pages_indexed;
    }

    public function has_sitemap()
    {
        return $this->has_sitemap;
    }
}

class Google_Helper_Site extends Google_Helper_Webmaster
{

    private static $type = 'SITE';
    private static $verificationMethod = 'META';

    public function __construct( Google_Client $client, $site_url )
    {
        parent::__construct( $client, $site_url );
        $this->site_verification = new \Google_Service_SiteVerification( $client );
    }

    public function add_site()
    {
        $this->sites->add( $this->site_url );
    }

    public function get_verification_token()
    {

        $request_site = new \Google_Service_SiteVerification_SiteVerificationWebResourceGettokenRequestSite();
        $request_site->setIdentifier( $this->site_url );
        $request_site->setType( static::$type );

        $request = new \Google_Service_SiteVerification_SiteVerificationWebResourceGettokenRequest();
        $request->setVerificationMethod( static::$verificationMethod );
        $request->setSite( $request_site );
        $site_token = $this->site_verification->webResource->getToken( $request );

        return $site_token->getToken();
    }

    public function verify_site()
    {
        $resource_site = new \Google_Service_SiteVerification_SiteVerificationWebResourceResourceSite();
        $resource_site->setIdentifier( $this->site_url );
        $resource_site->setType( static::$type );
        $resource = new \Google_Service_SiteVerification_SiteVerificationWebResourceResource();
        $resource->setSite( $resource_site );
        $this->site_verification->webResource->insert( static::$verificationMethod, $resource );
    }
}