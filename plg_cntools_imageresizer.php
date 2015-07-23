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
	protected $_fileType;
	
	/*---------------------------- constructor ----------------------------*/
	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->_fileType = '';
	}
	
	/*---------------------------- onContentBeforeSave ----------------------------*/
	public function onContentBeforeSave($context, &$article, $isNew)
	{
		if ($this->IsFileTypeOK($article)) {
			$workFileName = pathinfo($article->filepath, PATHINFO_FILENAME); //without file ext
			$workFileExt = pathinfo($article->name, PATHINFO_EXTENSION);
			
			// maybe encrypt file name
			$fileCrypt = $this->params->get('filecrypt', '0'); 
			if( $fileCrypt != '0') {
				if ($fileCrypt == '2') {
					$workFileName = md5($article->name);
				} elseif ($fileCrypt == '3') {
					$workFileName = sha1($article->name);
				} elseif ($fileCrypt == '4') {
					$workFileName = str_rot13($article->name);
				} elseif ($fileCrypt == '5') {
					$workFileName = '';
				} else {
					$workFileName = uniqid();
				}
			}
			$prefixAnf = $this->renderTextbyRegex($this->params->get('prefixtxtanf', ''));
			$prefixEnd = $this->renderTextbyRegex($this->params->get('prefixtxtend', ''));
			$workFileName = $prefixAnf . $workFileName . $prefixEnd . '.' . $workFileExt;
			
			$filenameUpLow = $this->params->get('filenameUpLow', '0');
			if( $filenameUpLow != '0') {
				if ($filenameUpLow == '1') {
					$workFileName = mb_strtoupper($workFileName);
				} else {
					$workFileName = mb_strtolower($workFileName);
				}
			}
			
			$article->filepath = str_replace($article->name, $workFileName, $article->filepath);
		}
		return true;
	}
	
	/*---------------------------- onContentAfterSave ----------------------------*/
	public function onContentAfterSave($context, &$article, $isNew)
	{
		if ($this->IsFileTypeOK($article)) {
			// get current image sizes
			list($width, $height) = getimagesize($article->filepath);
			switch ( $this->_fileType ) {
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
			if ( $this->_fileType == 'image/jpeg' ) {
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
			$ratio = $this->params->get('percent');
			$resizeFlag = $this->params->get('algoritm');
			if ( $resizeFlag == '2' ) {
				/*------------------------- MAXSIDE CALCUALTION -------------------------*/
				$maxside = $this->params->get('maxside');
				if (($maxside<=$width) or ($maxside<=$height) or ($this->params->get('scaleup'))) {
					//need recals image size
					if ($width>=$height) {
						// new height calc needed
						$newwidth = $maxside;
						$newheight = round(($maxside * $height) / $width);
					} else {
						// new width calc needed
						$newheight = $maxside;
						$newwidth = round(($maxside * $width) / $height);
					}
				} else {
					// no new size calc needed
					$newwidth 	= $width;
					$newheight 	= $height;
				}
			} elseif ( $resizeFlag == '1' ) {
				/*------------------------- SIZE CALCUALTION -------------------------*/
				//$orientChanged = FALSE;
				$newwidth 	= $this->params->get('width');
				$newheight 	= $this->params->get('height');
				
				// let's check orientation
				if (($width>=$height) and ($newwidth>=$newwidth)) {
					// do nothing - its all ok
				} else {
					//$orientChanged = TRUE;
					$workValue = $newwidth;
					$newwidth = $newheight;
					$newheight = $workValue;
				}

				if (($newwidth<=$width) or ($newheight<=$height) or ($this->params->get('scaleup'))) {
					if ($width>=$height) {
						$newwidth = $newwidth;
						$workSize = round(($newwidth * $height) / $width);
						if ($newheight<=$workSize) {
							$newwidth = round(($width * $newheight) / $height);
						} else {
							$newheight = $workSize;
						}
					} else {
						$newheight = $newheight;
						$workSize = round(($width * $newheight) / $height);
						if ($newwidth <= $workSize){
							$newwidth = round(($newwidth * $height) / $width);
						} else {
							$newwidth = $workSize;
						}
					}
				} else {
					// no new size calc needed
					$newwidth 	= $width;
					$newheight 	= $height;
				}
			} else {
				/*------------------------- PERCENT CALCUALTION -------------------------*/
				if ( !$ratio ) {
					$ratio = 100;
				}
				$newwidth 	= $width * ($ratio/100);
				$newheight 	= $height * ($ratio/100);
			}
			
			// load new image
			$new = imagecreatetruecolor($newwidth, $newheight);
	
			// we must take care about png/gif transparency before resize
			if ( ($this->_fileType == 'image/gif') or ($this->_fileType == 'image/png') ){
				$transparency = imagecolortransparent($source);
	
				if ( ($this->_fileType == 'image/gif') and ($transparency >= 0) ){
					list($r, $g, $b) = array_values (imagecolorsforindex($source, $transparency));
					$transparency = imagecolorallocate($new, $r, $g, $b);
					imagefill($new, 0, 0, $transparency);
					imagecolortransparent($new, $transparency);
				}
				elseif ($this->_fileType == 'image/png') {
					imagealphablending($new, false);
					$color = imagecolorallocatealpha($new, 0, 0, 0, 127);
					imagefill($new, 0, 0, $color);
					imagesavealpha($new, true);
				}
			}
			
			/*------------------------- no resize AND no watermark => EXIT -------------------------*/
			if (( $this->params->get('watermarkflag', '0') == '0') and ($width == $newwidth) and ($height == $newheight)){
				imagedestroy($source);
				imagedestroy($new);
				return true;
			}
			
			// resize
			imagecopyresampled($new, $source, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
			
			// add watermark
			if( ($this->params->get('watermarkflag', '0') != '0') ){
				unset($watermarkimage);
				
				if( $this->params->get('watermarkflag', '0') == '1') {
					/*------------------------- watermark image -------------------------*/
					$watermarkFileName = JPATH_CONFIGURATION . DIRECTORY_SEPARATOR . $this->params->get('watermarkimage');
					if (file_exists($watermarkFileName) and (mb_strtolower(substr($watermarkFileName, -3)) == 'png')) {
						$watermarkimage = imagecreatefrompng($watermarkFileName);
					}
				} elseif( $this->params->get('watermarkflag', '0') == '2') {
					/*------------------------- watermark text -------------------------*/
					// The text to draw
					$text = $this->params->get('watermark');
					if ($text!=''){
						// transfer used text by regex
						$text = $this->renderTextbyRegex($text);
						
						$font_size = ($size = $this->params->get('watermarkFontSize')) ? $size : 10;
						
						// Replace path by your own font path - https://googlefontdirectory.googlecode.com/hg/ofl/
						$font =  JPATH_CONFIGURATION . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'plg_cntools_imageresizer' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . $this->params->get('watermarkFontType', 'clrn') . '.ttf';
						$font = $this->params->get('watermarkFontURL', $font);
						
						$watermarkSize = imagettfbbox($font_size, 0/*angle*/, $font, $text);
						$width_wm = abs($watermarkSize[4] - $watermarkSize[0]) + $font_size;
						$height_wm = abs($watermarkSize[5] - $watermarkSize[1]) + $font_size;						
						$watermarkimage = imagecreatetruecolor($width_wm, $height_wm);

						$opacity = ( $opacity = $this->params->get('watermarkOpacity') ) ? ($opacity * 127)/100 : 0;
						$rgbcolor = $this->params->get('watermarkFontColor', '#000');
						$fontcolor = $this->hex2rgb($rgbcolor);

						imagesavealpha($watermarkimage, true);
						$transparent = imagecolorallocatealpha($watermarkimage, 0, 0, 0, 127);
						imagefill($watermarkimage, 0, 0, $transparent);

						$color = imagecolorallocatealpha($watermarkimage, $fontcolor[0], $fontcolor[1], $fontcolor[2], $opacity);
						imagettftext($watermarkimage, $font_size, 0/*angle*/, $font_size / 2/*X*/, $height_wm - ($font_size / 2)/*Y*/, $color, $font, $text);
					}
				}
				
				if (isset($watermarkimage)){
					$watermarkOffsetHor = $this->params->get('watermarkOffsetHor', '0');
					$watermarkOffsetVer = $this->params->get('watermarkOffsetVer', '0');
					
					$width_wm = imagesx($watermarkimage);
					$height_wm = imagesy($watermarkimage);
					$width_ori = imagesx($new);
					$height_ori = imagesy($new);
					
					// default values for left - top
					$pos_x = 0 + $watermarkOffsetHor;
					switch (mb_strtoupper($this->params->get('watermarkPosHor'))) {
						case ('C' ) : //CENTER
							$pos_x = ($width_ori - $width_wm) / 2;
						break;
						case ('R' ) : //RIGHT
							$pos_x = $width_ori - $width_wm - $watermarkOffsetHor;
						break;
					}

					$pos_y = 0 + $watermarkOffsetVer;
					switch (mb_strtoupper($this->params->get('watermarkPosVer'))) {
						case ('C' ) : //CENTER
							$pos_y = ($height_ori - $height_wm) / 2;
						break;
						case ('B' ) : //BOTTOM
							$pos_y = $height_ori - $height_wm - $watermarkOffsetVer;
						break;
					}
					 
					imagecopy($new, $watermarkimage, $pos_x, $pos_y, 0, 0, $width_wm, $height_wm);
					imagedestroy($watermarkimage);
				}
			}

			// Output
			switch ( $this->_fileType ) {
				case ('image/jpeg') : 
					imagejpeg($new, $article->filepath, 100);
				break;
	
				case('image/png') :
					imagepng($new, $article->filepath, 0);
				break;
	
				case('image/gif') :
					imagegif($new, $article->filepath);
				break;
			}
			
			imagedestroy($source);
			imagedestroy($new);
		}

		return true;
	}
	
	/*---------------------------- renderTextbyRegex ----------------------------*/
	private function renderTextbyRegex(&$text){
		// transfer date variables into right format
		$regex = "#{date\b(.*?)\}(.*?){/date}#s";
		$text = preg_replace_callback($regex, array('plgContentPlg_CNTools_ImageResizer', 'renderWandermarkTextDate'), $text);
		
		// transfer meta variables into right format
		$regex = "#{meta\b(.*?)\}(.*?){/meta}#s";
		$text = preg_replace_callback($regex, array('plgContentPlg_CNTools_ImageResizer', 'renderWandermarkTextMeta'), $text);
		
		// transfer user variables into right format
		$regex = "#{user\b(.*?)\}(.*?){/user}#s";
		$text = preg_replace_callback($regex, array('plgContentPlg_CNTools_ImageResizer', 'renderWandermarkTextUser'), $text);
		
		return $text;
	}
	
	/*---------------------------- renderWandermarkTextDate ----------------------------*/
	private function renderWandermarkTextDate(&$matches){
		return date(trim($matches[2]));
	}

	/*---------------------------- renderWandermarkTextMeta ----------------------------*/
	private function renderWandermarkTextMeta(&$matches){
		$app = JFactory::getApplication();
		return trim($app->getCfg($matches[2]));
	}

	/*---------------------------- renderWandermarkTextUser ----------------------------*/
	private function renderWandermarkTextUser(&$matches){
		$user = JFactory::getUser();
		return trim($user->get($matches[2]));
	}
	
	/*---------------------------- IsFolderOK ----------------------------*/
	private function IsFolderOK($folderBase) {
		$folderAllowed = true;
		
		/*$article->filepath*/
		if ($folderBase != '') {
			$folderBase = mb_strtolower($folderBase);
			$excludeFlag = $this->params->get('excludeFlag', '0');
			if (($excludeFlag != '0') and ($this->params->get('excludeFolder') != '')) {
				$folderBase = str_replace('\\', '/', $folderBase);
				$workFolder = str_replace('\\', '/', $this->params->get('excludeFolder'));
				$folders = explode(',', mb_strtolower($workFolder));
				
				if ($excludeFlag == '1') {
					//All but ...
					foreach ($folders as $folder) {
						if (strpos($folderBase, $folder)) {
							$folderAllowed = false;
						}
					}
				} elseif ($excludeFlag == '2') {
					//None, except for...
					$folderAllowed = false;
					foreach ($folders as $folder) {
						if (strpos($folderBase, $folder)) {
							$folderAllowed = true;
						}
					}
				}
			}
		}
		
		return $folderAllowed;
	}
	
	/*---------------------------- IsFileTypeOK ----------------------------*/
	private function IsFileTypeOK(&$article) {
		if (!is_object($article)) {
			return false;
		}
		
		if (!$this->IsFolderOK($article->filepath)) {
			return false;
		}

		if (!isset($article->type)) {
			return false;
		}
		
		// if we upload files by using non-flash uploader
		if ( $article->type != 'application/octet-stream' ) {
			$this->_fileType = $article->type;
		// if it's using flash uploader(multiple files) the $article->type will be application/octet-stream
		} else {
			if (function_exists('finfo_file')) {
				$finfo = new finfo(FILEINFO_MIME_TYPE);
				$this->_fileType = $finfo->file( $article->filepath );

			} elseif(function_exists('mime_content_type')) {
				$this->_fileType = mime_content_type( $article->filepath );

			} else {
				$temp 	= explode('.',$article->filepath);
				$key 	= count($temp)-1;
				if(isset($temp[$key])) {
					$this->_fileType = mb_strtolower($temp[$key]);
				}
				if ( $this->_fileType == 'jpg' ) {
					$this->_fileType = 'jpeg';
				}
				$this->_fileType = 'image/' . $this->_fileType;
			}
		}
		
		// if it's not a picture or is not supported, it's not our business :)
		if ($this->params->get('choosefiletype'))
		{
			$imagesMIME = (array)$this->params->get('filetypes');
		} else {
			$imagesMIME = array('image/jpeg', 'image/png', 'image/gif');
		}
		
		if (in_array($this->_fileType, $imagesMIME)) {
			return true;
		} else {
			return false;
		}
	}
	
	/*---------------------------- hex2rgb ----------------------------*/
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
