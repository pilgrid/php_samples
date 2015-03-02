<?php
define('DOCUMENT_ROOT','/www/data/ubik.trutv.com') ;
class AssetManager {

    private $asset;  // immutable once set
    private $new_asset;
    private $url = NULL; // returned and set by successful call to u()

    private $file; //full path to asset
    private $type;
    private $versioning_method;
    private $fingerprint;
    private $ssl;
    private $squash_prefixes = array();  // array of prefixes to remove if present
    private $passthru_prefixes = array();
    private $cdn;
    private $cdnssl;
    private $cdnzip;
    private $cdnnow;
    private $cdnmod;
    private $docroot;
    private $static_assets = array('jpg','jpeg','png','gif','swf','tif','bmp','js','css');
    private $zipped_assets = array('js','css');
    public static  $cdnftphost = '';

    const SPACER = '/graphics/spacer.gif';
    const VERSIONING_NONE = 100; // no fingerprinting
    const VERSIONING_QUERYSTRING = 101; // appends '?v=<SHA1_HASH>' to resource
    const VERSIONING_PATH = 102; // (mod_rewrite) prepends '/far-future/<SHA1_HASH>' to resource
    const VERSIONING_EXPIRESNOW = 103; // use CDN with immediate expiration
    const VERSIONING_TIMESTAMP = 104; // (mod_rewrite) insert filemod time between filename and suffix i.e. /path/to/file.1197993206.png

    const TEST_CDN='http://i.cdn.ubik.trutv.com/trutv/trutv.com';
    const TEST_CDNFF='http://cdn-ff.ubik.trutv.com/trutv/trutv.com';
    const TEST_CDNZIP='http://z.cdn.ubik.trutv.com/trutv/trutv.com';
    const TEST_CDNSSL='http://i.cdn.ubik.trutv.com/trutv/trutv.com';
    const TEST_CDNNOW='http://now.cdn.ubik.trutv.com/trutv/trutv.com';
    const TEST_CDNMOD='http://mod.cdn.ubik.trutv.com/trutv/trutv.com';

    function __construct($asset=NULL,$versioning_method = self::VERSIONING_NONE,$ssl=FALSE,$squash_prefixes = array('http://www.trutv.com'),$passthru_prefixes=array('http://'))
    {
        // if asset is not defined, use the spacer and throw an error
        // automatically sets mutable variable new_asset
        try {
            if(!empty($asset))
                $this->setAsset($asset); // automatically renders squashed_asset
            else{
                $this->setAsset(self::SPACER);
                throw new AssetManagerException('Asset not defined properly, set to default value.');
            }
        }catch (AssetManagerException $e) {

        }

        // get file extension (type)
        try {
            $matches=array();
            if(preg_match("/\.([^\.]+)$/", $this->new_asset, $matches))
                    $this->type = $matches[1];
            else
                throw new AssetManagerException('Cannot determine asset type from filename.');
        }catch (AssetManagerException $e) {

        }

        // check if file extension is acceptable
        try {
            if(!in_array($this->type,$this->static_assets))
                throw new AssetManagerException('Asset doesn\'t appear to be of an acceptable type: '.implode(',',$this->static_assets).'.');
        }catch (AssetManagerException $e) {

        }

        // set the document root, in case $_SERVER is not available
        $this->docroot =  defined('DOCUMENT_ROOT') ? DOCUMENT_ROOT : '/www/data/ubik.trutv.com' ;

        // get the full path to the file
        $this->file = $this->docroot.$this->new_asset;

        // set the versioning method, defaulting to VERSIONING_NONE if there are issues
        try {
            if($versioning_method >= self::VERSIONING_NONE && $versioning_method <= self::VERSIONING_TIMESTAMP)
                $this->versioning_method=$versioning_method;
            else {
                $this->versioning_method=self::VERSIONING_NONE;
                throw new AssetManagerException('Versioning method out of range: '.$versioning_method.'. Setting to VERSIONING_NONE.');
            }
        }catch (AssetManagerException $e) {

        }

        // set fingerprint, if needed
        if(in_array($this->versioning_method,array(self::VERSIONING_QUERYSTRING,self::VERSIONING_PATH))) {
            $this->fingerprint=substr(sha1_file($this->file),0,7);
            if(empty($this->fingerprint)) {;}//throw new AssetManagerException('Error creating fingerprint.');
        }

        // other stuff
        $this->ssl=$ssl;  // TODO: implement ssl properly
        $this->squash_prefixes=$squash_prefixes;
        $this->passthru_prefixes=$passthru_prefixes;
        $this->spacer=isset($GLOBALS['assetmanager_spacer']) ? $GLOBALS['assetmanager_spacer'] : self::SPACER;

        $this->cdn = isset($GLOBALS['cdnval']) ? $GLOBALS['cdnval'] : self::TEST_CDN;
        $this->cdnff = isset($GLOBALS['cdnvalff']) ? $GLOBALS['cdnvalff'] : self::TEST_CDNFF;
        $this->cdnzip = isset($GLOBALS['cdnvalzip']) ? $GLOBALS['cdnvalzip'] : self::TEST_CDNZIP;
        $this->cdnssl = isset($GLOBALS['cdnvalssl']) ? $GLOBALS['cdnvalssl'] : self::TEST_CDNSSL;
        $this->cdnnow = isset($GLOBALS['cdnvalnow']) ? $GLOBALS['cdnvalnow'] : self::TEST_CDNNOW;
        $this->cdnmod = isset($GLOBALS['cdnvalmod']) ? $GLOBALS['cdnvalmod'] : self::TEST_CDNMOD;

    }


    public function getAsset() {
        try {
            if(property_exists($this,'asset')) return $this->asset;
            else throw AssetManagerException('Asset not defined.');
        }catch (AssetManagerException $e) {

        }
    }

    // make asset immutable
    protected function setAsset($asset) {
        if(isset($this->asset)) return;
        else {
            $this->asset=trim($asset);
            $this->new_asset=$this->asset;
            if(!empty($this->squash_prefixes)) {
                foreach($this->squash_prefixes as $prefix) {
                    if(stripos($this->asset,$prefix) !== FALSE && stripos($this->asset,$prefix) == 0) {
                        $this->new_asset=str_replace($prefix,'',$this->asset);
                    }
                }
            }
        }
    }


    private function doPassthru($asset)
    {
        foreach($this->passthru_prefixes as $prefix)
        {
            if(stripos($asset,$prefix) !== FALSE && stripos($asset,$prefix) == 0) return TRUE;
        }

        return FALSE;
    }


    /* return a url properly formatted for CDN */
    public function u()
    {
        // make sure this function is only executed once.
        if(!empty($this->url)) return $this->url;

        $this->url = $this->new_asset; // automatically created during initialization

        // don't do anything to files that start with the specified prefix
        if($this->doPassthru($this->new_asset)) return $this->url;

        switch($this->versioning_method) {

            case self::VERSIONING_EXPIRESNOW:
                $this->url = $this->cdnnow . $this->new_asset;
                break;

            // TODO: consider timestamping only js and css
            case self::VERSIONING_TIMESTAMP:
                try {
                    $path = pathinfo($this->new_asset);
                    $ver = '.'.filemtime($this->file).'.';
                    if(empty($path['basename']) || $ver == '..')
                        throw new AssetManagerException('Error creating versioned filename.');
                    else
                        $this->new_asset = $path['dirname'].'/'.str_replace('.', $ver, $path['basename']);
                }catch (AssetManagerException $e) {

                }
                $this->url = $this->cdnmod . $this->new_asset;
                break;

            /* the remaining modes all use the typical cdn setup */
            case self::VERSIONING_QUERYSTRING:
                try  {
                    if ($this->fingerprint) {
                        $this->new_asset = $this->new_asset . '?v=' . $this->fingerprint;

                    }
                    else
                        throw new AssetManagerException('Fingerprinting of query string failed. Check the file location.');
                }catch (AssetManagerException $e) {

                }
                //echo "<b>$this->url</b><br/>";
                $this->url = $this->cdn . $this->new_asset;
                //echo "<b>$this->url</b><br/>";
                break;
            case self::VERSIONING_PATH:
                try {
                    if($this->fingerprint)
                        $this->new_asset = '/far-future/' . $this->fingerprint . $this->new_asset;
                    else
                        throw new AssetManagerException('Fingerprinting of path failed. Check the file location.');
                }catch (AssetManagerException $e) {

                }
                $this->url = $this->cdn . $this->new_asset;
                break;
            case self::VERSIONING_NONE:
            default:
                $this->url = $this->cdn . $this->new_asset;

        }

        if($this->ssl || array_key_exists('HTTPS', $_SERVER)) $this->url = $this->cdnssl.$this->new_asset;

        if(in_array($this->type,$this->zipped_assets)) $this->url=$this->cdnzip.$this->new_asset;
        return $this->url;

    }


    public function getHeaders() {
        $ch = curl_init();

        $url = $this->u();
        echo "<pre class=\"url\">$url</pre>";
        // set url
        curl_setopt($ch, CURLOPT_URL, $url);

        //return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_NOBODY, 1);
        curl_setopt($ch, CURLOPT_HEADER,1);

        // $output contains the output string
        $output = curl_exec($ch);
        $lines = explode("\n",trim($output));

        // the first line contains the response code
        $status = array_shift($lines);
        $result=array();
        foreach($lines as $line) {
           list($key,$val) = explode(': ',$line);
           $result[$key] = $val;
        }
        return $result;
    }


    public static function setCdnFtpHost($host) {
        self::$cdnftphost = $host;
    }
    public static function ftp2cdn($assets=NULL) {
        if(empty($assets)) {
            echo 'You must provide at least one asset to upload.' . PHP_EOL;
            return false;
        }


        if(empty(self::$cdnftphost)) {
            echo 'You must define a receiving cdn. Use AssetManager::setCdnFtpHost($host)'.PHP_EOL;
            return false;
        }
        ftp_connect(self::$cdnftphost)
        if(is_array($assets)) {

        }
    }
    static function http_report($id,$asset=NULL,$method=self::VERSIONING_NONE,$title='',$info='',$pre=NULL) {
        //if(empty($this->url)) $this->u();

        if(is_callable($pre)) call_user_func($pre);

        $am = new AssetManager($asset,$method);
        $img_src = $am->u();
        $headers=$am->getHeaders();
        $response=print_r($headers,TRUE);

        echo <<<REPORT
        <div id="$id">
            <pre class="method">$title</pre>
            <h5 class="info">$info</h5>
            <pre class="http">$response</pre>
            <img src="$img_src" />
        </div>
REPORT;
    }

}
class AssetManagerException extends Exception{}
?>
