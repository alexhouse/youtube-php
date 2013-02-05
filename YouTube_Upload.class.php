<?php
/**
 * YouTube Direct upload script, used to upload a given file directly to YouTube
 *
 **/

require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Gdata_YouTube');
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');

class YouTube_Upload
{
  protected $config = Array(
    'yt_username'   => '[username]',
    'yt_password'   => '[password]',

    'developerKey'  => '', // https://code.google.com/apis/youtube/dashboard/gwt/index.html
    'applicationId' => '[Text description of this application]',
    'clientId'      => '[Text description of this application]',

    'playlistId'    => '[Playlist ID to upload videos to]',
  );


  protected $httpClient;
  protected $yt;
  protected $playlist = NULL;

  public static $lastError;

  public function __construct ()
  {
    try
    {
      $httpClient = Zend_Gdata_ClientLogin::getHttpClient(
        $username = $this->config['yt_username'],
        $password = $this->config['yt_password'],
        $service = 'youtube',
        $client = NULL,
        $source = 'LKK YouTube Uploader',
        $loginToken = NULL,
        $loginCaptcha = NULL,
        $authUrl = 'https://www.google.com/accounts/ClientLogin'
      );
    }
    catch ( Exception $e )
    {
      echo __LINE__, ': ', $e;
    }

    try
    {
      $yt = new Zend_Gdata_YouTube($httpClient, $this->config['applicationId'], $this->config['clientId'], $this->config['developerKey']);
      $yt->setMajorProtocolVersion(2);
    }
    catch ( Exception $e )
    {
      echo __LINE__, ': ', $e;
    }

    $this->yt         = & $yt;
    $this->httpClient = & $httpClient;

    $this->getPlaylist();
  }

  private function getPlaylist ()
  {
    try
    {
      $playlists = $this->yt->getPlaylistListFeed('default');
      foreach ( $playlists as $id => $playlist )
        if ( $playlist->getPlaylistId() == $this->config['playlistId'] )
        {
          $this->playlist = $playlist;
          break;
        }
    }
    catch ( Exception $e )
    {
      echo __LINE__, ': ', $e;
    }
  }

  public function getVideosInPlaylist ()
  {
    echo 'Playlist URL: ', $this->playlist->getPlaylistVideoFeedUrl(), "\n";
    $videos = $this->yt->getPlaylistVideoFeed($this->playlist->getPlaylistVideoFeedUrl());
    $return = Array();

    foreach ( $videos as $video )
    {
      $arr = Array(
        'id'          => $video->getVideoId(),
        'title'       => $video->getVideoTitle(),
        'description' => $video->getVideoDescription(),
        'category'    => $video->getVideoCategory(),
        'tags'        => $video->getVideoTags(),
        'duration'    => $video->getVideoDuration(),
        'views'       => $video->getVideoViewCount(),
        'flash_url'   => $video->getFlashPlayerUrl(),
        'watch_url'   => $video->getVideoWatchPageUrl(),
        'url'         => 'http://youtu.be/' . $video->getVideoId(),
      );

      $return[] = $arr;
    }

    return $return;
  }

  /**
   * putVideo($path, $title, $description = NULL, $add_to_playlist = TRUE)
   * Pushes a video from the server to YouTube, and adds it to the playlist if available
   *
   * @param   string $path            Path to video
   * @param   string $title           Video title
   * @param   string $description     Video description
   * @param   bool   $add_to_playlist Whether or not to add the video to the given playlist too
   *
   * @return  mixed  YouTube Video ID if video uploaded successfully, -1 if file not found/unreadable or FALSE if error
   **/
  public function putVideo ( $path, $title, $description = NULL, $add_to_playlist = TRUE )
  {
    // reset last error field
    $this->_resetLastError();

    if ( !file_exists($path) || !is_readable($path) )
    {
      $this->_setLastError(__METHOD__, __LINE__, 'File does not exist, or file is not readable', Array( 'path' => $path ));

      return -1;
    }

    $video = new Zend_Gdata_YouTube_VideoEntry();

    $src = $this->yt->newMediaFileSource($path);
    $src->setContentType('video/mpg');
    $src->setSlug(basename($path));

    $video->setMediaSource($src);
    $video->setVideoTitle($title);
    $video->setVideoDescription($description ? : $title);
    $video->setVideoCategory('Howto');
    $video->setVideoTags('lkk,recipe');
    $video->setVideoDeveloperTags(Array( 'LKKUploader', 'Pending' ));

    try
    {
      // try to upload the new video
      $new = $this->yt->insertEntry($video, 'http://uploads.gdata.youtube.com/feeds/api/users/default/uploads', 'Zend_Gdata_YouTube_VideoEntry');

      // if uploaded, set the protocol version to 2 (required for below calls)
      $new->setMajorProtocolVersion(2);

      /*
       * Watch URL: $new->getvideoWatchPageUrl()
       *  Video ID: $new->getVideoId()
       */

      // check if we want to add the new video to a playlist (and that we have a playlist)
      if ( $add_to_playlist && $this->playlist != NULL )
      {
        // create a playlist entry using the DOM value of the new entry
        $playlist_entry = $this->yt->newPlaylistListEntry($new->getDOM());

        try
        {
          // attempt to insert the entry
          $this->yt->insertEntry($playlist_entry, $this->playlist->getPlaylistVideoFeedUrl());
        }
        catch ( Zend_App_Exception $e )
        {
          $this->_setLastError(__METHOD__, __LINE__, 'Could not insert new entry into playlist', Array( 'Exception' => $e->getMessage() ));
        }
      }

      // at this point we've succeeded, so return the new Video ID.
      return $new->getVideoId();
    }
    catch ( Zend_Gdata_App_HttpException $httpException )
    {
      $this->_setLastError(__METHOD__, __LINE__, 'Zend HTTP Exception', Array( 'Exception' => $httpException->getRawResponseBody() ));
    }
    catch ( Zend_Gdata_App_Exception $e )
    {
      $this->_setLastError(__METHOD__, __LINE__, 'Zend App Exception', Array( 'Exception' => $e->getMessage() ));
    }

    // if we've got here then chances are we're coming from one of the catch blocks, so we've failed
    // :-(
    return FALSE;
  }

  public function removeVideoFromPlaylist ( $id )
  {
    // if no playlist, return -1
    if ( $this->playlist === NULL )
    {
      $this->_setLastError(__METHOD__, __LINE__, 'No playlist available to delete from');

      return -1;
    }

    // loop each playlist entry
    foreach ( $this->yt->getPlaylistVideoFeed($this->playlist->getPlaylistVideoFeedUrl()) as $entry )
    {
      // compare IDs
      if ( $entry->getVideoId() == $id )
      {
        // match == delete
        $entry->delete();

        // entry deleted. Success!
        return TRUE;
      }
    }

    // nothing happened, return FALSE
    return FALSE;
  }

  /**
   * delVideo($id)
   * Deletes a video from the playlist & account
   *
   * @param   string  $id   The YouTube ID of the video to delete
   *
   * @return  boolean  TRUE on success, FALSE on failure
   **/
  public function delVideo ( $id )
  {
    $this->_resetLastError();

    if ( $id == '' )
    {
      $this->_setLastError(__METHOD__, __LINE__, 'ID cannot be null!');

      return FALSE;
    }

    try
    {
      $resp = $this->yt->delete($this->yt->getVideoEntry($id, NULL, TRUE));
      var_dump($resp);
      echo 'Video deleted', '<br>';
    }
    catch ( GDataResourceNotFoundExceptionVideo $e )
    {
      echo 'Video not found, presumed already deleted', '<br>';
    }
    catch ( Exception $e )
    {
      echo $e;
    }

    return TRUE;
  }

  public function updateVideo ( $id, $title, $desc )
  {
    if ( $id == '' )
      return -1;

    $entry = $this->yt->getVideoEntry($id, NULL, TRUE);
    $entry->setMajorProtocolVersion(2);
    echo 'Entry ID: ', $entry->getVideoId(), "\n";
    $entry->setVideoTitle($title);
    $entry->setVideoDescription($desc);
    echo 'Edit Link: ', $entry->getEditLink()->getHref(), "\n";
    try
    {
      $this->yt->updateEntry($entry, $entry->getEditLink()->getHref());
    }
    catch ( Exception $e )
    {
      echo $e;
    }
  }

  protected function _resetLastError ()
  {
    self::$lastError = NULL;
  }

  protected function _setLastError ( $method, $line, $error, Array $additional = NULL )
  {
    self::$lastError = (object)Array(
      'Method'     => $method,
      'Line'       => $line,
      'Error'      => $error,
      'Additional' => $additional
    );
  }

  protected static function getLastError ()
  {
    return self::$lastError;
  }
}


class YouTubeFileUnreadableException extends Exception
{
}
