# youtube-php

Classes &amp; files to aid PHP connections to the YouTube API for direct uploads and such.

This is in no way complete and is currently a work in progress.

## Documentation
### Methods

1. putVideo

    bool putVideo($path, $title, Array $extra = NULL)

1. removeVideo

    bool removeVideo($id)

1. modifyVideo

    bool modifyVideo($id, $key, $value = '')

1. addVideoToPlaylist

    bool addVideoToPlaylist($id, $playlistID)

1. removeVideoFromPlaylist

    bool removeVideoFromPlaylist($id, $playlistID)
  
1. getVideosInPlaylist
  
    array getVideosInPlaylist($playlistID)

1. getLastError

    string getLastError()

