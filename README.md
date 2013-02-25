youtube-php
===========

Classes &amp; files to aid PHP connections to the YouTube API for direct uploads and such.

This is in no way complete and is currently a work in progress.

# Documentation
## Methods

1. putVideo
		bool putVideo($path, $title, Array $extra = NULL)
2. removeVideo
		bool removeVideo($id)
3. modifyVideo
		bool modifyVideo($id, $key, $value = '')
4. addVideoToPlaylist
		bool addVideoToPlaylist($id, $playlistID)
5. removeVideoFromPlaylist
		bool removeVideoFromPlaylist($id, $playlistID)
6. getVideosInPlaylist
		array getVideosInPlaylist($playlistID)
7. getLastError
		string getLastError()
