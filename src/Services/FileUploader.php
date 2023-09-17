<?php

namespace App\Services;

use Symfony\Component\Filesystem\Filesystem;

class FileUploader {

    public function __construct(
        private Filesystem $fs,
        private $profileFolder,
        private $profileFolderPublic
    )
    {
        
    }

    public function uploadProfileImage($picture, $oldPicture = null)
    {
        $ext = $picture->guessExtension() ?? 'bin';
        $filename = bin2hex(random_bytes(10)) . '.' . $ext;
        $picture->move($this->profileFolder, $filename);
        if($oldPicture) {
            $this->fs->remove($this->profileFolder . '/' . pathinfo($oldPicture, PATHINFO_BASENAME));
        }

        return $this->profileFolderPublic . '/' . $filename;
    }
}


?>