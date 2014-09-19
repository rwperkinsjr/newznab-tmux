<?php
require_once(WWW_DIR . "/lib/framework/db.php");
require_once(WWW_DIR . "/lib/util.php");

/**
 * This class handles storage and retrieval of release preview files obtained via ffmpeg.
 */
class ReleaseImage
{
	/**
	 * Default constructor.
	 */
	function ReleaseImage()
	{
		$this->imgSavePath = NN_COVERS . 'preview' . DS;
		$this->audSavePath = NN_COVERS . 'audiosample' . DS;
		$this->jpgSavePath = NN_COVERS . 'sample' . DS;
		$this->movieImgSavePath = NN_COVERS . 'movies' . DS;
		$this->vidSavePath = NN_COVERS . 'video' . DS;
	}

	/**
	 * Return an image from a path.
	 */
	public function fetchImage($imgLoc)
	{
		$img = false;
		if (preg_match('/^http:/i', $imgLoc))
			$img = Utility::getUrl([$imgLoc]);
		elseif (file_exists($imgLoc))
			$img = @file_get_contents($imgLoc);

		if ($img !== false) {
			$im = @imagecreatefromstring($img);
			if ($im !== false) {
				imagedestroy($im);

				return $img;
			}

			return false;
		}

		return false;
	}

	/**
	 * Create an image at a path and a thumbnailed version.
	 */
	public function saveImage($imgName, $imgLoc, $imgSavePath, $imgMaxWidth = '', $imgMaxHeight = '', $saveThumb = false)
	{
		$cover = $this->fetchImage($imgLoc);
		if ($cover === false)
			return 0;

		if ($imgMaxWidth != '' && $imgMaxHeight != '') {
			$im = @imagecreatefromstring($cover);
			$width = imagesx($im);
			$height = imagesy($im);
			$ratioh = $imgMaxHeight / $height;
			$ratiow = $imgMaxWidth / $width;
			$ratio = min($ratioh, $ratiow);
			// New dimensions
			$new_width = intval($ratio * $width);
			$new_height = intval($ratio * $height);
			if ($new_width < $width) {
				$new_image = imagecreatetruecolor($new_width, $new_height);
				imagecopyresampled($new_image, $im, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
				ob_start();
				imagejpeg($new_image, null, 85);
				$thumb = ob_get_clean();
				imagedestroy($new_image);

				if ($saveThumb)
					@file_put_contents($imgSavePath . $imgName . '_thumb.jpg', $thumb);
				else
					$cover = $thumb;

				unset($thumb);
			}
			imagedestroy($im);
		}
		$coverPath = $imgSavePath . $imgName . '.jpg';
		$coverSave = @file_put_contents($coverPath, $cover);

		return ($coverSave !== false || ($coverSave === false && file_exists($coverPath))) ? 1 : 0;
	}

	/**
	 * Delete images for the release.
	 *
	 * @param string      $guid   The GUID of the release.
	 *
	 * @return void
	 */
	public function delete($guid)
	{
		$thumb = $guid . '_thumb.jpg';

		// Audiosample folder.
		@unlink($this->audSavePath . $guid . '.ogg');

		// Preview folder.
		@unlink($this->imgSavePath . $thumb);

		// Sample folder.
		@unlink($this->jpgSavePath . $thumb);

		// Video folder.
		@unlink($this->vidSavePath . $guid . '.ogv');
	}
}