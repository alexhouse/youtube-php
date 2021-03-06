<?php
/**
 * YouTube Direct upload script, used to upload a given file directly to YouTube
 *
 * @requires  Zend_Gdata_Youtube
 * @requires  Zend_Gdata_ClientLogin
 **/

require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Gdata_YouTube');
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');

/**
 *
 */
class YouTube_Upload
{
	/**
	 * @var array $config		An array of key configuration details
	 */
	protected $config = Array(
		'yt_username'   => '', // YouTube account username
		'yt_password'   => '', // YouTube account password

		'developerKey'  => '', // YouTube account developer key - you can get this via https://code.google.com/apis/youtube/dashboard/gwt/index.html
		'applicationId' => '', // Text description of the application (e.g. "My-YouTube-uploader")
		'clientId'      => '' // Text description of the client (e.g. "My-YouTube-client") - both applicationID and clientID are just arbitrary strings 
	);

	/**
	 * @var	Zend_Http_Client	$httpClient	Holds a reference to the Zend HTTP client
	 */
	protected $httpClient;
	/**
	 * @var Zend_Gdata_YouTube	$yt			Holds a reference to the Zend_Gdata_YouTube client
	 */
	protected $yt;

	/**
	 * @var string		$lastError	A message containing the last error text
	 * @static
	 */
	public static $lastError;

	/**
	 * __construct ()
	 * Constructs the YouTube_Upload class
	 */
	public function __construct ()
	{
		try
		{
			/**
			 * getHttpClient expects the following:
			 * getHttpClient(
			 * 	$username
			 * 	$password
			 * 	$service      (Which Google service is required)
			 * 	$client				(Zend_Gdata_HttpClient if already exists)
			 * 	$source				(application ID)
			 * 	$loginToken		(only required if interactive)
			 * 	$loginCaptcha	(only required if interactive)
			 * 	$loginUri
			 * 	$accountType	(optional)
			 * )
			 */
			$httpClient = Zend_Gdata_ClientLogin::getHttpClient(
				$this->config['yt_username'],
				$this->config['yt_password'],
				'youtube',
				NULL,
				$this->config['applicationId'],
				NULL,
				NULL,
				'https://www.google.com/accounts/ClientLogin'
			);
		}
		catch ( Exception $e )
		{
			throw new YTUHttpClientException(__LINE__, $e);
		}

    // successfully logged in, so now we try to open a reference to the YouTube api (V2)
		try
		{
			$yt = new Zend_Gdata_YouTube($httpClient, $this->config['applicationId'], $this->config['clientId'], $this->config['developerKey']);
			$yt->setMajorProtocolVersion(2);
		}
		catch ( Exception $e )
		{
			throw new YTUGdataYouTubeException(__LINE__, $e);
		}

    // set the variables
		$this->yt         = $yt;
		$this->httpClient = $httpClient;
	}

	/**
	 * getPlaylist ( $pid )
	 * Gets a playlist by ID
   *
   * @param String $pid Playlist ID to find
   * @return mixed Zend_Gdata_YouTube_PlaylistListEntry if playlist found, otherwise FALSE.
   * @throws IllegalArgumentException
	 */
	private function getPlaylist ( $pid )
  {
    if ( !isset($id) )
    {
      throw new IllegalArgumentException('Must provide a valid playlist ID');
    }

    // try find the playlist
		try
		{
			$playlists = $this->yt->getPlaylistListFeed('default');
			foreach ( $playlists as $id => $playlist )
			{
				if ( $playlist->getPlaylistId() == $pid )
				{
					return $playlist;
				}
			}
		}
		catch ( Exception $e )
		{
			echo __LINE__, ': ', $e;
    }

    return FALSE;
	}

  /**
   * getVideosInPlaylist ( $id )
   * Gets a list of videos in the provided playlist
   *
   * @param String  $pid  Playlist ID
   * @return Array An array of the videos in the playlist
   * @throws IllegalArgumentException
	 */
	public function getVideosInPlaylist ()
  {
    if ( !isset($id) )
    {
      throw new IllegalArgumentException('Must provide a valid playlist ID');
    }

		$playlist = $this->getPlaylist($pid);

		echo 'Playlist URL: ', $playlist->getPlaylistVideoFeedUrl(), "\n";
		$videos = $this->yt->getPlaylistVideoFeed($playlist->getPlaylistVideoFeedUrl());
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
	 * putVideo($path, $title, $description = NULL)
	 * Pushes a video from the server to YouTube, and adds it to the playlist if available
	 *
	 * @param   string $path    Physical path to video on the source server
	 * @param   string $title   Video title
	 * @param   Array  $extra   Additional video details (description|category|tags)
	 *
	 * @return  mixed  YouTube Video ID if video uploaded successfully, FALSE if error
	 * @throws  YTUFileUnreadableException
	 **/
	public function putVideo ( $path, $title, Array $extra = NULL )
	{
		// reset last error field
		$this->_resetLastError();

    // can't read the path
		if ( !file_exists($path) || !is_readable($path) )
		{
			$this->_setLastError(__METHOD__, __LINE__, 'File does not exist, or file is not readable', Array( 'path' => $path ));
			throw new YTUFileUnreadableException('File does not exist, or file is not readable');
		}

    // create a new Zend space
		$video = new Zend_Gdata_YouTube_VideoEntry();

		$src = $this->yt->newMediaFileSource($path);
		$src->setContentType('video/mpg');
		$src->setSlug(basename($path));

		$video->setMediaSource($src);
    $video->setVideoTitle($title);

    // add in the extra details
		if ( isset($extra['description']) )
		{
			$video->setVideoDescription($extra['description']);
		}

		if ( isset($extra['category']) )
		{
			$video->setVideoCategory($extra['category']);
		}

		if ( isset($extra['tags']) )
		{
			$video->setVideoTags($extra['tags']);
		}

		try
		{
			// try to upload the new video - this may take a while
			$new = $this->yt->insertEntry($video, 'http://uploads.gdata.youtube.com/feeds/api/users/default/uploads', 'Zend_Gdata_YouTube_VideoEntry');

			// if uploaded, set the protocol version to 2 (required for below calls)
			$new->setMajorProtocolVersion(2);

			/*
			 * Watch URL: $new->getvideoWatchPageUrl()
			 *  Video ID: $new->getVideoId()
			 */

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

		// if we've got here then we're coming from one of the catch blocks, so we've failed
		// :-(
		return FALSE;
	}

	public function addVideoToPlaylist ( $vid, $playlist = NULL )
	{
		// check if we want to add the new video to a playlist (and that we have a playlist)

		if ( $playlist == NULL || $playlist == '' )
		{
			throw new InvalidArgumentException('You must pass a valid playlist Id');
		}

		if ( $vid == NULL || $vid == '' )
		{
			throw new InvalidArgumentException('Video ID cannot be empty');
		}

    $entry = $this->yt->getVideoEntry($vid, NULL, TRUE);

		// create a playlist entry using the DOM value of the new entry
		$playlist_entry = $this->yt->newPlaylistListEntry($entry->getDOM());

		try
		{
			// attempt to insert the entry
			$this->yt->insertEntry($playlist_entry, $this->getPlaylist($playlist)->getPlaylistVideoFeedUrl());
		}
		catch ( Zend_App_Exception $e )
		{
			$this->_setLastError(__METHOD__, __LINE__, 'Could not insert new entry into playlist', Array( 'Exception' => $e->getMessage() ));
		}
	}

	/**
	 * @param $id
	 *
	 * @return bool|int
	 */
	public function removeVideoFromPlaylist ( $id, $playlist = NULL )
	{
		if ( $playlist == NULL || $playlist == '' )
		{
			throw new InvalidPlaylistIdException('You must pass a valid playlist Id');
		}

		// loop each playlist entry
		foreach ( $this->yt->getPlaylistVideoFeed($this->getPlaylist($playlist)->getPlaylistVideoFeedUrl()) as $entry )
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
	 * removeVideo($id)
	 * Deletes a video from the playlist & account
	 *
	 * @param   string  $id   The YouTube ID of the video to delete
	 *
	 * @return  boolean  TRUE on success, FALSE on failure
	 * @throws	InvalidArgumentException
	 **/
	public function removeVideo ( $id )
	{
		$this->_resetLastError();

		if ( $id == '' || $id == NULL )
		{
			throw new InvalidArgumentException('Video ID cannot be empty');
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

	/**
	 * modifyVideo($id, $key, $value)
	 *
	 * Update a YouTube video's properties
	 *
	 * @param String $id       The Video ID to update
	 * @param String $key      The property to update
	 * @param String $value    The new value for the property
	 *
	 * @return bool    True on success, false on failure
	 * @throws  InvalidArgumentException
	 * @throws  YTUUpdateException
	 */
	public function modifyVideo ( $id, $key, $value = '' )
	{
		if ( $id == '' || $id == NULL )
		{
			throw new InvalidArgumentException('Video ID cannot be empty');
		}

		$entry = $this->yt->getVideoEntry($id, NULL, TRUE);
		$entry->setMajorProtocolVersion(2);

		switch ( $key )
		{
			case 'title':
				$entry->setVideoTitle($value);
				break;
			case 'desc':
			case 'description':
				$entry->setVideoDescription($value);
				break;
		}

		//echo 'Edit Link: ', $entry->getEditLink()->getHref(), "\n";

		try
		{
			$this->yt->updateEntry($entry, $entry->getEditLink()->getHref());
		}
		catch ( Exception $e )
		{
			throw new YTUUpdateException($e);
		}
	}

	/**
	 * @param       $method
	 * @param       $line
	 * @param       $error
	 * @param array $additional
	 */
	protected function _setLastError ( $method, $line, $error, Array $additional = NULL )
	{
		self::$lastError = (object) Array(
			'Method'     => $method,
			'Line'       => $line,
			'Error'      => $error,
			'Additional' => $additional
		);
	}

	/**
	 * Resets the last error
	 */
	protected function _resetLastError ()
	{
		self::$lastError = NULL;
	}

	/**
	 * @return mixed
	 */
	protected static function getLastError ()
	{
		return self::$lastError;
	}
}


/**
 *
 */
class YTUFileUnreadableException extends Exception
{
}

class YTUUpdateException extends Exception
{
}

class YTUHttpClientException extends Exception
{
}

class YTUGdataYouTubeException extends Exception {}
