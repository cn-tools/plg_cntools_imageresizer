<?php
/*
 * @component plg_cntools_imageresizer
 * @website : https://github.com/cn-tools/plg_cntools_imageresizer
 * @copyright Copyright (c) 2014 Clemens Neubauer. All Rights Reserved.
 * @license : http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */
 
// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die('Restricted access');

// import library dependencies
jimport('joomla.plugin.plugin');

class plgContentPlg_CNTools_ImageResizer extends JPlugin
{
	public function onContentAfterSave($context, &$article, $isNew)
	{
		$imagesMIME = array('image/jpeg', 'image/png', 'image/gif');
		
		if (!isset($article->type)) {
			return;
		}
		
		// if we upload files by using non-flash uploader
		if ( $article->type != 'application/octet-stream' ) {
			
			$type = $article->type;
		
		// if it's using flash uploader(multiple files) the $article->type will be application/octet-stream
		} else {

			if (function_exists('finfo_file')) {
				$finfo = new finfo(FILEINFO_MIME_TYPE);
				$type = $finfo->file( $article->filepath );

			} elseif(function_exists('mime_content_type')) {
				$type = mime_content_type( $article->filepath );

			} else {
				$temp 	= explode('.',$article->filepath);
				$key 	= count($temp)-1;
				if(isset($temp[$key])) {
					$type	= strtolower($temp[$key]);
				}
				if ( $type == 'jpg' ) {
					$type = 'jpeg';
				}
				$type = 'image/' . $type;
			}
		}
		
		// if it's not a picture or is not supported, it's not our business :)
		if (!in_array($type,$imagesMIME)) {
			return true;
		}

		// get current image sizes
		list($width, $height) = getimagesize($article->filepath);

		switch ( $type ) {
			case ('image/jpeg') : 
				$source = imagecreatefromjpeg($article->filepath);
			break;

			case('image/png') :
				$source = imagecreatefrompng($article->filepath);
			break;

			case('image/gif') :
				$source = imagecreatefromgif($article->filepath);
			break;
		}
		
		// let's take care about orientation
		// only for JPEG
		if ( $type == 'image/jpeg' ) {
			$orientation 	= 1;
			$exif 			= @exif_read_data($article->filepath); 
			if(isset($exif['Orientation'])) {
				$orientation = $exif['Orientation'];
			}

			switch($orientation) {

                case 3: // 180 rotate left
               	 	$source = imagerotate ($source,180,0);
                break;

                case 6: // 90 rotate right
                   	$source = imagerotate ($source,270,0);
                   	$temp 	= array('width'=>$height, 'height'=>$width);
                   	$width 	= $temp['width'];
                   	$height = $temp['height'];
                break;


                case 8: // 90 rotate left
                   	$source = imagerotate ($source,90,0);
                    $temp 	= array('width'=>$height, 'height'=>$width);
                   	$width 	= $temp['width'];
                   	$height = $temp['height'];
                break;
            }
		}

		// get sizes for the new image
		if ( $this->params->get('algoritm') ) {
			$orientChanged = FALSE;
			$newwidth 	= $this->params->get('width');
			$newheight 	= $this->params->get('height');
			
			// let's check orientation
			if ( $this->params->get('orientation') ) {
				if (($width>=$height) AND ($newwidth>=$newwidth)) {
					// do nothing - its all ok
				} else {
					$orientChanged = TRUE;
					$lWorkValue = $newwidth;
					$newwidth = $newheight;
					$newheight = $lWorkValue;
				}
			}
			
			// let's check scale up section
			if (!$this->params->get('scaleup')) {
				// smaller images are not enlarged
				if ($width<=$newwidth) {
					unset($newwidth);
				}
				if ($height<=$newheight) {
					unset($newheight);
				}
			}

			// let's calc new size of image
			if ( !$newwidth && !$newheight ) { 
				return;
			} elseif (  !$newwidth ) {
				$initial = $width/$height;
				$newwidth = $newheight * $initial;
			} elseif ( !$newheight ) {
				$initial = $width/$height;
				$newheight = $newwidth/$initial;
			} elseif ($orientChanged) {
				$newwidth = ($width * $newheight) / $height;
			} else {
				$newheight = ($height * $newwidth) / $width;
			}
		} else {
			$ratio	 	= $this->params->get('percent');
			if ( !$ratio ) {
				return;
			}
			$newwidth 	= $width * ($ratio/100);
			$newheight 	= $height * ($ratio/100);
		}

		
		// load new image
		$new = imagecreatetruecolor($newwidth, $newheight);

		// we must take care about png/gif transparency before resize
		if ( $type == 'image/gif' || $type == 'image/png' ){
			$transparency = imagecolortransparent($source);

			if ( $type == 'image/gif' && $transparency >= 0 ){
				list($r, $g, $b) = array_values (imagecolorsforindex($source, $transparency));
				$transparency = imagecolorallocate($new, $r, $g, $b);
				imagefill($new, 0, 0, $transparency);
				imagecolortransparent($new, $transparency);
			}
			elseif ($type == 'image/png') {
				imagealphablending($new, false);
				$color = imagecolorallocatealpha($new, 0, 0, 0, 127);
				imagefill($new, 0, 0, $color);
				imagesavealpha($new, true);
			}
		}
		
		// resize
		imagecopyresampled($new, $source, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
		
		// add watermark
		if( $this->params->get('watermark')) {

			// The text to draw
			$text = $this->params->get('watermark');
			$font_size = ($size = $this->params->get('watermarkFontSize')) ? $size : 10;
			$opacity = ( $opacity = $this->params->get('watermarkOpacity') ) ? ($opacity * 127)/100 : 0;
			if ( $rgbcolor = $this->params->get('watermarkFontColor') ) {
				$fontcolor = $this->hex2rgb($rgbcolor);
				$color = imagecolorallocatealpha($new, $fontcolor[0], $fontcolor[1], $fontcolor[2], $opacity);
			} else {
				$color = imagecolorallocatealpha($new, 0, 0, 0, $opacity);
			}
			
			// Replace path by your own font path
			$font =  JPATH_CONFIGURATION . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'plg_cntools_imageresizer' . DIRECTORY_SEPARATOR . 'clrn.ttf';
			
			$coordx = ($font_size + 10);
			$coordy = ($font_size + 10);

			imagettftext($new, $font_size, 0, 10, $font_size, $color, $font, $text);
		}

		// Output
		switch ( $type ) {
			case ('image/jpeg') : 
				imagejpeg($new, $article->filepath,100);
			break;

			case('image/png') :
				imagepng($new, $article->filepath,0);
			break;

			case('image/gif') :
				imagegif($new, $article->filepath);
			break;
		}
		
		imagedestroy($source);
		imagedestroy($new);

		return true;
	}

	private function hex2rgb($hex) {

	   $hex = str_replace("#", "", $hex);

	   if(strlen($hex) == 3) {
	      $r = hexdec(substr($hex,0,1).substr($hex,0,1));
	      $g = hexdec(substr($hex,1,1).substr($hex,1,1));
	      $b = hexdec(substr($hex,2,1).substr($hex,2,1));
	   } else {
	      $r = hexdec(substr($hex,0,2));
	      $g = hexdec(substr($hex,2,2));
	      $b = hexdec(substr($hex,4,2));
	   }
	   $rgb = array($r, $g, $b);
	
	   return $rgb;
	}
}
?>
