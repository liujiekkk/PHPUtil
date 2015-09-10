<?
    /**
    *
    * Resizes Animated GIF Files
    *
    *   ///IMPORTANT NOTE: The script needs a temporary directory where all the frames should be extracted.
    *   Create a directory with a 777 permission level and write the path into $temp_dir variable below.
    *  
    *   Default directory is "frames".
    *   
    * update by liujie 2015.09.10  
    * 
    */
    class GifUtil {

        public $temp_dir = "frames";
        private $pointer = 0;
        private $index = 0;
        private $globaldata = array();
        private $imagedata = array();
        private $imageinfo = array();
        private $handle = 0;
        private $orgvars = array();
        private $encdata = array();
        private $parsedfiles = array();
        private $originalwidth = 0;
        private $originalheight = 0;
        private $wr,$hr;
        private $props = array();
        private $decoding = false;

        /**
        * Public part of the class
        *
        * @orgfile - Original file path
        * @newfile - New filename with path
        * @width   - Desired image width
        * @height  - Desired image height
        */
        function resize($orgfile,$newfile,$width,$height,$type=2){
            $this->decode($orgfile);
            $this->wr=$width/$this->originalwidth;
            $this->hr=$height/$this->originalheight;
            $size = array();
            $this->resizeframes($width,$height,$type,$size);
            $this->encode($newfile,$size['width'],$size['height']);
            $this->clearframes();
        }  

        /**
        * GIF Decoder function.
        * Parses the GIF animation into single frames.
        */
        private function decode($filename){
            $this->decoding = true;           
            $this->clearvariables();
            $this->loadfile($filename);
            $this->get_gif_header();
            $this->get_graphics_extension(0);
            $this->get_application_data();
            $this->get_application_data();
            $this->get_image_block(0);
            $this->get_graphics_extension(1);
            $this->get_comment_data();
            $this->get_application_data();
            $this->get_image_block(1);
            while(!$this->checkbyte(0x3b) && !$this->checkEOF()){
                $this->get_comment_data(1);
                $this->get_graphics_extension(2);
                $this->get_image_block(2);
            }
            $this->writeframes(time());     
            $this->closefile();
            $this->decoding = false;
        }

        /**
        * GIF Encoder function.
        * Combines the parsed GIF frames into one single animation.
        */
        private function encode($new_filename,$newwidth,$newheight){
            $mystring = "";
            $this->pointer = 0;
            $this->imagedata = array();
            $this->imageinfo = array();
            $this->handle = 0;
            $this->index=0;

            $k=0;
            foreach($this->parsedfiles as $imagepart){
                $this->loadfile($imagepart);
                $this->get_gif_header();
                $this->get_application_data();
                $this->get_comment_data();
                $this->get_graphics_extension(0);
                $this->get_image_block(0);

                //get transparent color index and color
                if(isset($this->encdata[$this->index-1]))
                    $gxdata = $this->encdata[$this->index-1]["graphicsextension"];
                else
                    $gxdata = null;
                $ghdata = $this->imageinfo["gifheader"];
                $trcolor = "";
                $hastransparency=($gxdata[3]&&1==1);

                if($hastransparency){
                    $trcx = ord($gxdata[6]);
                    $trcolor = substr($ghdata,13+$trcx*3,3);
                }

                //global color table to image data;
                $this->transfercolortable($this->imageinfo["gifheader"],$this->imagedata[$this->index-1]["imagedata"]);

                $imageblock = &$this->imagedata[$this->index-1]["imagedata"];

                //if transparency exists transfer transparency index
                if($hastransparency){
                    $haslocalcolortable = ((ord($imageblock[9])&128)==128);
                    if($haslocalcolortable){
                        //local table exists. determine boundaries and look for it.
                        $tablesize=(pow(2,(ord($imageblock[9])&7)+1)*3)+10;
                        $this->orgvars[$this->index-1]["transparent_color_index"] =
                        ((strrpos(substr($this->imagedata[$this->index-1]["imagedata"],0,$tablesize),$trcolor)-10)/3);       
                    }else{
                        //local table doesnt exist, look at the global one.
                        $tablesize=(pow(2,(ord($gxdata[10])&7)+1)*3)+10;
                        $this->orgvars[$this->index-1]["transparent_color_index"] =
                        ((strrpos(substr($ghdata,0,$tablesize),$trcolor)-10)/3);   
                    }              
                }

                //apply original delay time,transparent index and disposal values to graphics extension

                if(!$this->imagedata[$this->index-1]["graphicsextension"]) $this->imagedata[$this->index-1]["graphicsextension"] = chr(0x21).chr(0xf9).chr(0x04).chr(0x00).chr(0x00).chr(0x00).chr(0x00).chr(0x00);

                $imagedata = &$this->imagedata[$this->index-1]["graphicsextension"];

                $imagedata[3] = chr((ord($imagedata[3]) & 0xE3) | ($this->orgvars[$this->index-1]["disposal_method"] << 2));
                $imagedata[4] = chr(($this->orgvars[$this->index-1]["delay_time"] % 256));
                $imagedata[5] = chr(floor($this->orgvars[$this->index-1]["delay_time"] / 256));
                if($hastransparency){
                    $imagedata[6] = chr($this->orgvars[$this->index-1]["transparent_color_index"]);
                }
                $imagedata[3] = chr(ord($imagedata[3])|$hastransparency);

                //apply calculated left and top offset
                $imageblock[1] = chr(round(($this->orgvars[$this->index-1]["offset_left"]*$this->wr) % 256));
                $imageblock[2] = chr(floor(($this->orgvars[$this->index-1]["offset_left"]*$this->wr) / 256));
                $imageblock[3] = chr(round(($this->orgvars[$this->index-1]["offset_top"]*$this->hr) % 256));
                $imageblock[4] = chr(floor(($this->orgvars[$this->index-1]["offset_top"]*$this->hr) / 256));          

                if($this->index==1){
                    if(!isset($this->imageinfo["applicationdata"]) || !$this->imageinfo["applicationdata"])
                        $this->imageinfo["applicationdata"]=chr(0x21).chr(0xff).chr(0x0b)."NETSCAPE2.0".chr(0x03).chr(0x01).chr(0x00).chr(0x00).chr(0x00);
                    if(!isset($this->imageinfo["commentdata"]) || !$this->imageinfo["commentdata"])
                        $this->imageinfo["commentdata"] = chr(0x21).chr(0xfe).chr(0x10)."PHPGIFRESIZER1.0".chr(0);
                    $mystring .= $this->orgvars["gifheader"]. $this->imageinfo["applicationdata"].$this->imageinfo["commentdata"];
                    if(isset($this->orgvars["hasgx_type_0"]) && $this->orgvars["hasgx_type_0"]) $mystring .= $this->globaldata["graphicsextension_0"];
                    if(isset($this->orgvars["hasgx_type_1"]) && $this->orgvars["hasgx_type_1"]) $mystring .= $this->globaldata["graphicsextension"];
                }

                $mystring .= $imagedata . $imageblock;
                $k++;
                $this->closefile();
            }

            $mystring .= chr(0x3b);

            //applying new width & height to gif header
            $mystring[6] = chr($newwidth % 256);
            $mystring[7] = chr(floor($newwidth / 256));
            $mystring[8] = chr($newheight % 256);
            $mystring[9] = chr(floor($newheight / 256));
            $mystring[11]= $this->orgvars["background_color"];
            //if(file_exists($new_filename)){unlink($new_filename);}
            file_put_contents($new_filename,$mystring);
        }

        /**
        * Variable Reset function
        * If a instance is used multiple times, it's needed. Trust me.
        */
        private function clearvariables(){
            $this->pointer = 0;
            $this->index = 0;
            $this->imagedata = array();
            $this->imageinfo = array();           
            $this->handle = 0;
            $this->parsedfiles = array();
        }

        /**
        * Clear Frames function
        * For deleting the frames after encoding.
        */
        private function clearframes(){
            foreach($this->parsedfiles as $temp_frame){
                unlink($temp_frame);
            }
        }

        /**
        * Frame Writer
        * Writes the GIF frames into files.
        */
        private function writeframes($prepend){
            for($i=0;$i<sizeof($this->imagedata);$i++){
                file_put_contents($this->temp_dir."/frame_".$prepend."_".str_pad($i,2,"0",STR_PAD_LEFT).".gif",$this->imageinfo["gifheader"].$this->imagedata[$i]["graphicsextension"].$this->imagedata[$i]["imagedata"].chr(0x3b));
                $this->parsedfiles[]=$this->temp_dir."/frame_".$prepend."_".str_pad($i,2,"0",STR_PAD_LEFT).".gif";
            }
        }

        /**
        * Color Palette Transfer Device
        * Transferring Global Color Table (GCT) from frames into Local Color Tables in animation.
        */
        private function transfercolortable($src,&$dst){
            //src is gif header,dst is image data block
            //if global color table exists,transfer it
            if((ord($src[10])&128)==128){
                //Gif Header Global Color Table Length
                $ghctl = pow(2,$this->readbits(ord($src[10]),5,3)+1)*3;
                //cut global color table from gif header
                $ghgct = substr($src,13,$ghctl);
                //check image block color table length
                if((ord($dst[9])&128)==128){
                    //Image data contains color table. skip.
                }else{
                    //Image data needs a color table.
                    //get last color table length so we can truncate the dummy color table
                    $idctl = pow(2,$this->readbits(ord($dst[9]),5,3)+1)*3;
                    //set color table flag and length  
                    $dst[9] = chr(ord($dst[9]) | (0x80 | (log($ghctl/3,2)-1)));
                    //inject color table
                    $dst = substr($dst,0,10).$ghgct.substr($dst,-1*strlen($dst)+10);
                }
            }else{
                //global color table doesn't exist. skip.
            }
        }

        /**
        * GIF Parser Functions.
        * Below functions are the main structure parser components.
        */
        private function get_gif_header(){
            $this->p_forward(10);
            if($this->readbits(($mybyte=$this->readbyte_int()),0,1)==1){
                $this->p_forward(2);
                $this->p_forward(pow(2,$this->readbits($mybyte,5,3)+1)*3);
            }else{
                $this->p_forward(2);
            }

            $this->imageinfo["gifheader"]=$this->datapart(0,$this->pointer);
            if($this->decoding){
                $this->orgvars["gifheader"]=$this->imageinfo["gifheader"];
                $this->originalwidth = ord($this->orgvars["gifheader"][7])*256+ord($this->orgvars["gifheader"][6]);
                $this->originalheight = ord($this->orgvars["gifheader"][9])*256+ord($this->orgvars["gifheader"][8]);
                $this->orgvars["background_color"]=$this->orgvars["gifheader"][11];
            }

        }
        //-------------------------------------------------------
        private function get_application_data(){
            $startdata = $this->readbyte(2);
            if($startdata==chr(0x21).chr(0xff)){
                $start = $this->pointer - 2;
                $this->p_forward($this->readbyte_int());
                $this->read_data_stream($this->readbyte_int());
                $this->imageinfo["applicationdata"] = $this->datapart($start,$this->pointer-$start);
            }else{
                $this->p_rewind(2);
            }
        }
        //-------------------------------------------------------
        private function get_comment_data(){
            $startdata = $this->readbyte(2);
            if($startdata==chr(0x21).chr(0xfe)){
                $start = $this->pointer - 2;
                $this->read_data_stream($this->readbyte_int());
                $this->imageinfo["commentdata"] = $this->datapart($start,$this->pointer-$start);
            }else{
                $this->p_rewind(2);
            }
        }
        //-------------------------------------------------------
        private function get_graphics_extension($type){
            $startdata = $this->readbyte(2);
            if($startdata==chr(0x21).chr(0xf9)){
                $start = $this->pointer - 2;
                $this->p_forward($this->readbyte_int());
                $this->p_forward(1);
                if($type==2){
                    $this->imagedata[$this->index]["graphicsextension"] = $this->datapart($start,$this->pointer-$start);
                }else if($type==1){
                    $this->orgvars["hasgx_type_1"] = 1;
                    $this->globaldata["graphicsextension"] = $this->datapart($start,$this->pointer-$start);
                }else if($type==0 && $this->decoding==false){
                    $this->encdata[$this->index]["graphicsextension"] = $this->datapart($start,$this->pointer-$start);
                }else if($type==0 && $this->decoding==true){
                    $this->orgvars["hasgx_type_0"] = 1;
                    $this->globaldata["graphicsextension_0"] = $this->datapart($start,$this->pointer-$start);
                }
            }else{
                $this->p_rewind(2);
            }
        }
        //-------------------------------------------------------
        private function get_image_block($type){
            if($this->checkbyte(0x2c)){
                $start = $this->pointer;
                $this->p_forward(9);
                if($this->readbits(($mybyte=$this->readbyte_int()),0,1)==1){
                    $this->p_forward(pow(2,$this->readbits($mybyte,5,3)+1)*3);
                }
                $this->p_forward(1);
                $this->read_data_stream($this->readbyte_int());
                $this->imagedata[$this->index]["imagedata"] = $this->datapart($start,$this->pointer-$start);

                if($type==0){
                    $this->orgvars["hasgx_type_0"] = 0;
                    if(isset($this->globaldata["graphicsextension_0"]))
                        $this->imagedata[$this->index]["graphicsextension"]=$this->globaldata["graphicsextension_0"];
                    else
                        $this->imagedata[$this->index]["graphicsextension"]=null;
                    unset($this->globaldata["graphicsextension_0"]);
                }elseif($type==1){
                    if(isset($this->orgvars["hasgx_type_1"]) && $this->orgvars["hasgx_type_1"]==1){
                        $this->orgvars["hasgx_type_1"] = 0;
                        $this->imagedata[$this->index]["graphicsextension"]=$this->globaldata["graphicsextension"];
                        unset($this->globaldata["graphicsextension"]);
                    }else{
                        $this->orgvars["hasgx_type_0"] = 0;
                        $this->imagedata[$this->index]["graphicsextension"]=$this->globaldata["graphicsextension_0"];
                        unset($this->globaldata["graphicsextension_0"]);
                    }
                }

                $this->parse_image_data();
                $this->index++;

            }
        }
        //-------------------------------------------------------
        private function parse_image_data(){
            $this->imagedata[$this->index]["disposal_method"] = $this->get_imagedata_bit("ext",3,3,3);
            $this->imagedata[$this->index]["user_input_flag"] = $this->get_imagedata_bit("ext",3,6,1);
            $this->imagedata[$this->index]["transparent_color_flag"] = $this->get_imagedata_bit("ext",3,7,1);
            $this->imagedata[$this->index]["delay_time"] = $this->dualbyteval($this->get_imagedata_byte("ext",4,2));
            $this->imagedata[$this->index]["transparent_color_index"] = ord($this->get_imagedata_byte("ext",6,1));
            $this->imagedata[$this->index]["offset_left"] = $this->dualbyteval($this->get_imagedata_byte("dat",1,2));
            $this->imagedata[$this->index]["offset_top"] = $this->dualbyteval($this->get_imagedata_byte("dat",3,2));
            $this->imagedata[$this->index]["width"] = $this->dualbyteval($this->get_imagedata_byte("dat",5,2));
            $this->imagedata[$this->index]["height"] = $this->dualbyteval($this->get_imagedata_byte("dat",7,2));
            $this->imagedata[$this->index]["local_color_table_flag"] = $this->get_imagedata_bit("dat",9,0,1);
            $this->imagedata[$this->index]["interlace_flag"] = $this->get_imagedata_bit("dat",9,1,1);
            $this->imagedata[$this->index]["sort_flag"] = $this->get_imagedata_bit("dat",9,2,1);
            $this->imagedata[$this->index]["color_table_size"] = pow(2,$this->get_imagedata_bit("dat",9,5,3)+1)*3;
            $this->imagedata[$this->index]["color_table"] = substr($this->imagedata[$this->index]["imagedata"],10,$this->imagedata[$this->index]["color_table_size"]);
            $this->imagedata[$this->index]["lzw_code_size"] = ord($this->get_imagedata_byte("dat",10,1));
            if($this->decoding){
                $this->orgvars[$this->index]["transparent_color_flag"] = $this->imagedata[$this->index]["transparent_color_flag"];
                $this->orgvars[$this->index]["transparent_color_index"] = $this->imagedata[$this->index]["transparent_color_index"];
                $this->orgvars[$this->index]["delay_time"] = $this->imagedata[$this->index]["delay_time"];
                $this->orgvars[$this->index]["disposal_method"] = $this->imagedata[$this->index]["disposal_method"];
                $this->orgvars[$this->index]["offset_left"] = $this->imagedata[$this->index]["offset_left"];
                $this->orgvars[$this->index]["offset_top"] = $this->imagedata[$this->index]["offset_top"];
            }
        }
        //-------------------------------------------------------
        private function get_imagedata_byte($type,$start,$length){
            if($type=="ext")
                return substr($this->imagedata[$this->index]["graphicsextension"],$start,$length);
            elseif($type=="dat")
                return substr($this->imagedata[$this->index]["imagedata"],$start,$length);
        }
        //-------------------------------------------------------
        private function get_imagedata_bit($type,$byteindex,$bitstart,$bitlength){
            if($type=="ext")
                return $this->readbits(ord(substr($this->imagedata[$this->index]["graphicsextension"],$byteindex,1)),$bitstart,$bitlength);
            elseif($type=="dat")
                return $this->readbits(ord(substr($this->imagedata[$this->index]["imagedata"],$byteindex,1)),$bitstart,$bitlength);
        }
        //-------------------------------------------------------
        private function dualbyteval($s){
            $i = ord($s[1])*256 + ord($s[0]);
            return $i;
        }
        //------------   Helper Functions ---------------------
        private function read_data_stream($first_length){
            $this->p_forward($first_length);
            $length=$this->readbyte_int();
            if($length!=0) {
                while($length!=0){
                    $this->p_forward($length);
                    $length=$this->readbyte_int();
                }
            }
            return true;
        }
        //-------------------------------------------------------
        private function loadfile($filename){
            $this->handle = fopen($filename,"rb");
            $this->pointer = 0;
        }
        //-------------------------------------------------------
        private function closefile(){
            fclose($this->handle);
            $this->handle=0;
        }
        //-------------------------------------------------------
        private function readbyte($byte_count){
            $data = fread($this->handle,$byte_count);
            $this->pointer += $byte_count;
            return $data;
        }
        //-------------------------------------------------------
        private function readbyte_int(){
            $data = fread($this->handle,1);
            $this->pointer++;
            return ord($data);
        }
        //-------------------------------------------------------
        private function readbits($byte,$start,$length){
            $bin = str_pad(decbin($byte),8,"0",STR_PAD_LEFT);
            $data = substr($bin,$start,$length);
            return bindec($data);
        }
        //-------------------------------------------------------
        private function p_rewind($length){
            $this->pointer-=$length;
            fseek($this->handle,$this->pointer);
        }
        //-------------------------------------------------------
        private function p_forward($length){
            $this->pointer+=$length;
            fseek($this->handle,$this->pointer);
        }
        //-------------------------------------------------------
        private function datapart($start,$length){
            fseek($this->handle,$start);
            $data = fread($this->handle,$length);
            fseek($this->handle,$this->pointer);
            return $data;
        }
        //-------------------------------------------------------
        private function checkbyte($byte){
            if(fgetc($this->handle)==chr($byte)){
                fseek($this->handle,$this->pointer);
                return true;
            }else{
                fseek($this->handle,$this->pointer);
                return false;
            }
        }  
        //-------------------------------------------------------
        private function checkEOF(){
            if(fgetc($this->handle)===false){
                return true;
            }else{
                fseek($this->handle,$this->pointer);
                return false;
            }
        }  
        //-------------------------------------------------------
        /**
        * Debug Functions.  keleyi.com
        * Parses the GIF animation into single frames.
        */
        private function debug($string){
            echo "<pre>";
            for($i=0;$i<strlen($string);$i++){
                echo str_pad(dechex(ord($string[$i])),2,"0",STR_PAD_LEFT). " ";
            }
            echo "</pre>";
        }
        //-------------------------------------------------------
        private function debuglen($var,$len){
            echo "<pre>";
            for($i=0;$i<$len;$i++){
                echo str_pad(dechex(ord($var[$i])),2,"0",STR_PAD_LEFT). " ";
            }
            echo "</pre>";
        }  
        //-------------------------------------------------------
        private function debugstream($length){
            $this->debug($this->datapart($this->pointer,$length));
        }
        //-------------------------------------------------------
        /**
        * GD Resizer Device
        * Resizes the animation frames
        */
//         private function resizeframes(){
//             $k=0;
//             foreach($this->parsedfiles as $img){
//                 $src = imagecreatefromgif($img);
//                 $sw = $this->imagedata[$k]["width"];
//                 $sh = $this->imagedata[$k]["height"];
//                 $nw = round($sw * $this->wr);
//                 $nh = round($sh * $this->hr);
//                 $sprite = imagecreatetruecolor($nw,$nh);   
//                 $trans = imagecolortransparent($sprite);
//                 imagealphablending($sprite, false);
//                 imagesavealpha($sprite, true);
//                 imagepalettecopy($sprite,$src);                
//                 imagefill($sprite,0,0,imagecolortransparent($src));
//                 imagecolortransparent($sprite,imagecolortransparent($src));                    
//                 imagecopyresized($sprite,$src,0,0,0,0,$nw,$nh,$sw,$sh);    
//                 imagegif($sprite,$img);
//                 imagedestroy($sprite);
//                 imagedestroy($src);
//                 $k++;
//             }
//         }
        
        /**
         * 每一帧的压缩函数
         * @param int $dstWidth		欲压缩的宽度
         * @param int $dstHeight	欲压缩的高度（type=2时候此参数无效）
         * @param int $type	压缩算法类型
         * 0、强制压缩，不按照比例缩放
         * 1、按照宽度压缩，锁定宽高比防止图片变形，此函数主要用来给app页面压图，减少流量
         * 2、图片按原图比例求交集然后缩放至指定尺寸，此函数得到的结果一定是铺满图片
         * 3、图片最大切割，多余补白，此函数得到的也是指定大小图片，但是当目的型号图片大于原图时候，
         * 	    目的型号和原图进行求交运算，其余部分进行补白操作；小于原图时候，按照目的型号求等比最
         *   大内切矩形，然后在缩放到指定尺寸。
         * @param array $size 此参数为不同算法图片压图最终型号计算结果
         * array('width'=>'最终宽度','height'=>'最终高度')
         */
        private function resizeframes($dstWidth,$dstHeight,$type=2,&$size=array()){
        	
        	$srcWidth  = $this->originalwidth;
        	$srcHeight = $this->originalheight;
        	// 初始化缩放后的图片的宽高
        	$src_x = 0;
        	$src_y = 0;
        	$src_width = $srcWidth;
        	$src_height = $srcHeight;
        	// 强制缩放（不考虑变形）
        	if($type==0){
        			// 超过原图大小不再缩略
        			$width   =  $dstWidth;
        			$height  =  $dstHeight;
        			// 以下目的宽高会使图片变形
        			$src_width = $dstWidth;
        			$src_height = $dstHeight;
        	}
        	// 按照宽度缩放图片
        	if($type==1){
        		$scale = $dstWidth/$srcWidth;
        		if($scale>=1 || $dstWidth>=$srcWidth) {
        			// 超过原图大小不再缩略
        			$width   =  $srcWidth;
        			$height  =  $srcHeight;
        		}else{
        			// 缩略图尺寸
        			$width  = $dstWidth;
        			$height = (int)($srcHeight*$scale);
        			$src_width = $dstWidth;
        			$src_height = $height;
        		}
        	}
        	// 图片按原图比例求交集然后缩放至指定尺寸
        	if($type==2){
	        	$scale = min($dstWidth/$srcWidth, $dstHeight/$srcHeight); // 计算缩放比例
	        	if($scale>=1) {		// 目的图片必须比原图片小
	        		$width   =  $srcWidth;
	        		$height  =  $srcHeight;
	        	}else{
	        		// 求出最大内接矩形
	        		if(($srcWidth/$dstWidth) < ($srcHeight/$dstHeight)){		// 以宽为主
	        			$src_width = $dstWidth;
	        			$src_height = intval($srcHeight/($srcWidth/$dstWidth));
	        			$src_x = 0;
	        			$src_y = ($srcHeight - $dstHeight * ($srcWidth/$dstWidth))/2;
	        		}else {
	        			$src_width = intval($srcWidth/($srcHeight/$dstHeight));
	        			$src_height = $dstHeight;
	        			$src_x = ($srcWidth - $dstWidth * ($srcHeight/$dstHeight))/2;
	        			$src_y = 0;
	        		}
	        		$width = $dstWidth;
	        		$height = $dstHeight;
	        	}
        	}
        	// 图片最大切割，多余补白
        	if($type==3){
        		$scale = min($dstWidth/$srcWidth, $dstHeight/$srcHeight); // 计算缩放比例
        		if($scale>=1) {		// 目的图片必须比原图片小
        			$width   =  $srcWidth;
        			$height  =  $srcHeight;
        		}else{
        			// 求出最大内接矩形
        			if(($srcWidth/$dstWidth) < ($srcHeight/$dstHeight)){		// 以宽为主
        				$src_width = $srcWidth<$dstWidth?$srcWidth:$dstWidth;
        				$temp_height = intval($srcHeight/($srcWidth/$dstWidth));
        				$src_height = $srcHeight<$temp_height?$srcHeight:$temp_height;
        				$src_x = 0;
        				$src_y = ($srcHeight - $dstHeight * ($srcWidth/$dstWidth))/2;
        			}else {
        				$temp_width = intval($srcWidth/($srcHeight/$dstHeight));
        				$src_width = $srcWidth<$temp_width?$srcWidth:$temp_width;
        				$src_height = $srcHeight<$dstHeight?$srcHeight:$dstHeight;
        				$src_x = ($srcWidth - $dstWidth * ($srcHeight/$dstHeight))/2;
        				$src_y = 0;
        			}
        			$width = $srcWidth<$dstWidth?$srcWidth:$dstWidth;
        			$height = $srcHeight<$dstHeight?$srcHeight:$dstHeight;
        		}
        	}
        	$size['width'] = $width;
        	$size['height'] =$height;
//         	echo "{$src_width}===={$src_height}===={$src_x}===={$src_y}===={$dstWidth}===={$dstHeight}===={$width}===={$height}===={$srcWidth}===={$srcHeight}";exit;
			// 压缩每一帧画面
        	$k=0;
        	foreach($this->parsedfiles as $img){
        		$src = imagecreatefromgif($img);
        		$sw = $this->imagedata[$k]["width"];
        		$sh = $this->imagedata[$k]["height"];
        		$sprite = imagecreatetruecolor($width,$height);
        		imagepalettecopy($sprite,$src);
        		imagefill($sprite,0,0,imagecolortransparent($src));
        		imagecolortransparent($sprite,imagecolortransparent($src));
        		// 复制图片
        		if(function_exists("ImageCopyResampled")){
        			imagecopyresampled($sprite, $src, 0, 0, $src_x, $src_y, $src_width, $src_height, $srcWidth, $srcHeight);
        		}else{
        			imagecopyresized($sprite, $src, 0, 0, $src_x, $src_y, $src_width, $src_height,  $srcWidth, $srcHeight);
        		}
        		imagegif($sprite,$img);
        		imagedestroy($sprite);
        		imagedestroy($src);
        		$k++;
        	}
        }
    }
?>