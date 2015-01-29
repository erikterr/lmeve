<?php

// CORS proxy
// https://web.ccpgamescdn.com/ccpwgl/res/   dx9/model/lensflare/orange.red\
//
// use: proxy.php?fetch=dx9/model/lensflare/orange.red
//
//use proxy for CCP WebGL assets
//$LM_CCPWGL_USEPROXY=FALSE;
//cache files proxied by WebGL proxy
//$LM_CCPWGL_PROXYCACHE=FALSE;
//log every and all attempts to use proxy
//$LM_CCPWGL_PROXYAUDIT=FALSE;
//database schema for WebGL proxy cache
//$LM_CCPWGL_CACHESCHEMA='lmeve-cache';
include_once(dirname(__FILE__).'/../../config/config.php');
include_once(dirname(__FILE__).'/../../include/db.php');

function file_header($mime) {
    //browser cache
    $days=90;
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
      $if_modified_since = preg_replace('/;.*$/', '',   $_SERVER['HTTP_IF_MODIFIED_SINCE']);
    } else {
      $if_modified_since = '';
    }
    $mtime = filemtime($_SERVER['SCRIPT_FILENAME']);
    $gmdate_mod = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

    if ($if_modified_since == $gmdate_mod) {
      header("HTTP/1.0 304 Not Modified");
      exit;
    }
    header("Last-Modified: $gmdate_mod");
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + (60*60*24*$days)) . ' GMT');
    
    //Add CORS header in header so API can be used with web apps on other servers
    header("Access-Control-Allow-Origin: *");

    //Add proper content-type header
    header("Content-type: $mime");
}

//get fetch var
$addr=$_GET['fetch'];

//prepare logging vars
if ($LM_CCPWGL_PROXYAUDIT) {
    $ip=$_SERVER['REMOTE_ADDR'];
    $fetch=secureGETstr('fetch',256);
    db_uquery("CREATE TABLE IF NOT EXISTS `$LM_CCPWGL_CACHESCHEMA`.`lmproxylog` (
      `logID` bigint(11) NOT NULL AUTO_INCREMENT,
      `timestamp` datetime NOT NULL,
      `ip` varchar(23) NOT NULL,
      `fetch` varchar(256) NOT NULL,
      `status` varchar(24) NOT NULL,
      `cacheUsed` int(11) NOT NULL,
      `url` varchar(256) NULL,
      `http_code` int(11) NULL,
      `bytes` bigint(11) NOT NULL,
      PRIMARY KEY (`logID`),
      KEY `status_key` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
}
//validate input using regexp
if ( !preg_match('/^[\w\d\.\/]+$/',$addr) || preg_match('/\.\./',$addr) ) {
    if ($LM_CCPWGL_PROXYAUDIT) db_uquery("INSERT INTO `$LM_CCPWGL_CACHESCHEMA`.`lmproxylog` VALUES(DEFAULT,NOW(),'$ip','$fetch','INVALID_FETCH',0,NULL,NULL,0);");
    die('Error: Filter error.');
}
$url = $LM_CCPWGL_URL.$addr;



if (!$LM_CCPWGL_USEPROXY) {
    header("Location: $url");
    if ($LM_CCPWGL_PROXYAUDIT) db_uquery("INSERT INTO `$LM_CCPWGL_CACHESCHEMA`.`lmproxylog` VALUES(DEFAULT,NOW(),'$ip','$fetch','PROXY_DISABLED',0,NULL,NULL,0);");
    die('Error: WebGL Proxy is disabled.');
}

if ($LM_CCPWGL_PROXYCACHE) {
    //we use proxy cache.
    db_uquery("CREATE TABLE IF NOT EXISTS `$LM_CCPWGL_CACHESCHEMA`.`lmproxyfiles` (
      `fileID` bigint(11) NOT NULL AUTO_INCREMENT,
      `timestamp` datetime NOT NULL,
      `url` varchar(256) NOT NULL,
      `mime` varchar(256) NOT NULL,
      `data` MEDIUMBLOB NOT NULL,
      PRIMARY KEY (`fileID`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
    // Check if we have that file already in DB
    $cache=db_asocquery("SELECT * FROM `$LM_CCPWGL_CACHESCHEMA`.`lmproxyfiles` WHERE `url`='$url'");
    if (count($cache)>0) {
        //CACHE HIT! we have the file in cache
        $data=$cache[0]['data'];
        $info=$cache[0]['mime'];
        if ($LM_CCPWGL_PROXYAUDIT) {
          $size=strlen($data);
          db_uquery("INSERT INTO `$LM_CCPWGL_CACHESCHEMA`.`lmproxylog` VALUES(DEFAULT,NOW(),'$ip','$fetch','OK',1,'$url',200,$size);");
        }
        file_header($info);
        echo($data);
        die();
    }
    //otherwise we do nothing, proxy will fall back to downloading the file using CURL
}


//download the file using CURL

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

  $data = curl_exec($ch);
  $info = curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
  $http_code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
  
  if ($info=='text/plain; charset=UTF-8') $info='text/xml; charset=UTF-8';
  $errno=curl_errno($ch);
        
  curl_close($ch);
  
  if ($data===FALSE) {
      //CURL error occured

      switch ($errno) {
          case 78:
              http_response_code(404);
              break;
          case 9:
              http_response_code(403);
              break;
          default:
              http_response_code(404);
              break;
      }
      if ($LM_CCPWGL_PROXYAUDIT) db_uquery("INSERT INTO `$LM_CCPWGL_CACHESCHEMA`.`lmproxylog` VALUES(DEFAULT,NOW(),'$ip','$fetch','CURL_ERROR_$errno',0,'$url',$http_code,0);");
  } else {
      //no error
      //if we use cache, we should save the retrieved file to the database
      if ($LM_CCPWGL_PROXYCACHE) {
          db_uquery("INSERT INTO `$LM_CCPWGL_CACHESCHEMA`.`lmproxyfiles` VALUES(DEFAULT,NOW(),'$url','$info','". mysql_escape_string($data) ."');");
      }
      //output!
      file_header($info);

      if ($LM_CCPWGL_PROXYAUDIT) {
          $size=strlen($data);
          $http_code == '200' ? $ok='OK' : $ok='HTTP_ERROR';
          db_uquery("INSERT INTO `$LM_CCPWGL_CACHESCHEMA`.`lmproxylog` VALUES(DEFAULT,NOW(),'$ip','$fetch','$ok',0,'$url',$http_code,$size);");
      }
      echo $data;
  }



?>