<?php

/*
 * Only the lines Pulse words differently. Laravel merges this file over its
 * own validation messages (FileLoader::loadPaths), so every other line is
 * unchanged.
 */
return [
    // Livewire shows this when the web server refuses an upload before the
    // app sees it — most often a size limit (nginx client_max_body_size, PHP
    // upload_max_filesize) below the size the form allows.
    'uploaded' => 'The :attribute could not be uploaded — the server refused the file, usually because it is larger than the server allows. Try a smaller file, or ask HR / IT to raise the upload limit.',
];
