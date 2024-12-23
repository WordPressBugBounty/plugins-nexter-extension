<?php 
	Class Nxt_custom_Fields_Components{
		/**
		 * Instance
		 */
		private static $instance = null;

		/**
		 * Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 */
		private function __construct() {
				
		}

		/** Below Functions For CSS Generator */

		/** Typography Start */
		public static function nxtTypoCss($val, $selector, $device){
			$data = [ 'md' => [], 'sm' => [], 'xs' => [] ];
			if(!empty($val) && !empty($val['openTypography'])){
				$typocss = '';
				if(!empty($val['fontFamily']) && !empty($val['fontFamily']['family']) && !empty($val['fontFamily']['type']) ){
					$typocss .= 'font-family: "'.$val['fontFamily']['family'].'",'.$val['fontFamily']['type'].';';
				}
				if(!empty($val['fontFamily']) && !empty($val['fontFamily']['fontWeight'])){
					$typocss .= 'font-weight: '.$val['fontFamily']['fontWeight'].';';
				}
				if(!empty($val['fontStyle'])){
					$typocss .= 'font-style: '.$val['fontStyle'].';';
				}
				if(!empty($val['textTransform'])){
					$typocss .= 'text-transform: '.$val['textTransform'].';';
				}
				if(!empty($val['textDecoration'])){
					$typocss .= 'text-decoration: '.$val['textDecoration'].';';
				}

				if(!empty($typocss)){
					$css = $selector.'{'.$typocss.'}';
					array_push( $device['md'], $css);
				}

				if (isset($val['size']) && $val['size']!='') {
					$data = self::_push( self::_device( $val['size'], 'font-size:{{key}}'), $data);
				}
				if (isset($val['height']) && $val['height']!='') {
					$data = self::_push( self::_device( $val['height'], 'line-height:{{key}}'), $data);
				}
				if (isset($val['spacing']) && $val['spacing']!='') {
					$data = self::_push( self::_device( $val['spacing'], 'letter-spacing:{{key}}'), $data);
				}

				if ($data['md']) {
					if(gettype($data['md']) == 'array' && $data['md'] != '' ){
						array_push( $device['md'], self::objectReplace($selector, $data['md']) );
					}else if( $data['md'] != '' ){
						array_push( $device['md'], $selector . '{' . $data['md'] . '}');
					}
				}
				if ($data['sm']) {
					if(gettype($data['sm']) == 'array' && $data['sm'] != '' ){
						array_push( $device['sm'], self::objectReplace($selector, $data['sm']) );
					}else if( $data['sm'] != '' ){
						array_push( $device['sm'], $selector . '{' . $data['sm'] . '}');
					}
				}
				if ($data['xs']) {
					if(gettype($data['xs']) == 'array' && $data['xs'] != '' ){
						array_push($device['xs'], self::objectReplace($selector, $data['xs']) );
					}else if( $data['xs'] != '' ){
						array_push( $device['xs'], $selector . '{' . $data['xs'] . '}' );
					}
				}
			}
			return $device;
		}
		/** Typography End */

		/** Border Start */
		public static function nxtBorderCss($val, $selector, $device){
			$data = [ 'md' => [], 'sm' => [], 'xs' => [] ];
			if(!empty($val) && !empty($val['openBorder'])){
				$bdrcss = '';
				if(!empty($val['type'])){
					$bdrcss .= 'border-style: '.$val['type'].';';
				}
				if(!empty($val['color'])){
					$bdrcss .= 'border-color: '.$val['color'].';';
				}

				if(!empty($bdrcss)){
					$css = $selector.'{'.$bdrcss.'}';
					array_push( $device['md'], $css);
				}

				if (gettype($val['width']) === 'array') {
					$data = self::_push(self::_customDevice($val['width'], 'border-width:{{key}};'), $data);

					if ($data['md']) {
						if(gettype($data['md']) == 'array' && $data['md'] != '' ){
							array_push( $device['md'], self::objectReplace($selector, $data['md']) );
						}else if( $data['md'] != '' ){
							array_push( $device['md'], $selector . '{' . $data['md'] . '}');
						}
					}
					if ($data['sm']) {
						if(gettype($data['sm']) == 'array' && $data['sm'] != '' ){
							array_push( $device['sm'], self::objectReplace($selector, $data['sm']) );
						}else if( $data['sm'] != '' ){
							array_push( $device['sm'], $selector . '{' . $data['sm'] . '}');
						}
					}
					if ($data['xs']) {
						if(gettype($data['xs']) == 'array' && $data['xs'] != '' ){
							array_push($device['xs'], self::objectReplace($selector, $data['xs']) );
						}else if( $data['xs'] != '' ){
							array_push( $device['xs'], $selector . '{' . $data['xs'] . '}' );
						}
					}
				}
			}
			return $device;
		}
		/** Border End */

		/** Dimension Start */
		public static function nxtDimensionCss($val, $selector,$property, $device){
			$data = [ 'md' => [], 'sm' => [], 'xs' => [] ];
			if(!empty($val)){
				if (gettype($val) === 'array') {
					$data = self::_push(self::_customDevice($val, $property.':{{key}};'), $data);
					if ($data['md']) {
						if(gettype($data['md']) == 'array' && $data['md'] != '' ){
							array_push( $device['md'], self::objectReplace($selector, $data['md']) );
						}else if( $data['md'] != '' ){
							array_push( $device['md'], $selector . '{' . $data['md'] . '}');
						}
					}
					if ($data['sm']) {
						if(gettype($data['sm']) == 'array' && $data['sm'] != '' ){
							array_push( $device['sm'], self::objectReplace($selector, $data['sm']) );
						}else if( $data['sm'] != '' ){
							array_push( $device['sm'], $selector . '{' . $data['sm'] . '}');
						}
					}
					if ($data['xs']) {
						if(gettype($data['xs']) == 'array' && $data['xs'] != '' ){
							array_push($device['xs'], self::objectReplace($selector, $data['xs']) );
						}else if( $data['xs'] != '' ){
							array_push( $device['xs'], $selector . '{' . $data['xs'] . '}' );
						}
					}
				}
				
				return $device;
			}

		}
		/** Dimension End */

		/** Box Shadow Start */
		public static function nxtShadowCss($val, $selector, $device){
			if(!empty($val)){
				$shadowCss = '';
				if(!empty($val['openShadow'])){
					$shadowCss = 'box-shadow:'.$val['inset'].' '.$val['horizontal'].'px '.$val['vertical'].'px '.$val['blur'].'px '.$val['spread'].'px '.$val['color'].';';
				}
				
				if(!empty($shadowCss)){
					$css = $selector.'{'.$shadowCss.'}';
					array_push( $device['md'], $css);
				}
			}
			return $device;
		}
		/** Box Shadow End */

		/** Range Start */
		public static function nxtRangeCss($val, $selector,$property,$unit, $device){
			$data = [ 'md' => [], 'sm' => [], 'xs' => [] ];
			if(!empty($val)){
				if(gettype($val) == 'array'){
					if (isset($val) && $val!='') {
						$data = self::_push( self::_device( $val, $property.':{{key}}'), $data);
					}
					if ($data['md']) {
						if(gettype($data['md']) == 'array' && $data['md'] != '' ){
							array_push( $device['md'], self::objectReplace($selector, $data['md']) );
						}else if( $data['md'] != '' ){
							array_push( $device['md'], $selector . '{' . $data['md'] . '}');
						}
					}
					if ($data['sm']) {
						if(gettype($data['sm']) == 'array' && $data['sm'] != '' ){
							array_push( $device['sm'], self::objectReplace($selector, $data['sm']) );
						}else if( $data['sm'] != '' ){
							array_push( $device['sm'], $selector . '{' . $data['sm'] . '}');
						}
					}
					if ($data['xs']) {
						if(gettype($data['xs']) == 'array' && $data['xs'] != '' ){
							array_push($device['xs'], self::objectReplace($selector, $data['xs']) );
						}else if( $data['xs'] != '' ){
							array_push( $device['xs'], $selector . '{' . $data['xs'] . '}' );
						}
					}
				}else if(gettype($val) == 'string'){
					if(!empty($val) && !empty($unit) && !empty($selector)){
						$css = $selector.'{ '.$property.': '.$val.$unit.'; }';
						array_push( $device['md'], $css);
					}	
				}
			}
			return $device;
		}
		/** Range End */

		/** Color Start */
		public static function nxtColorCss($val, $selector, $property, $device){
			if(!empty($val) && !empty($selector) && !empty($property)){
				$css = $selector.'{ '.$property.': '.$val.'; }';
				array_push( $device['md'], $css);
			}
			return $device;
		}
		/** Color End */

		/** Gradient Start */
		public static function nxtGradientCss($val, $selector, $device){
			if(!empty($val) && !empty($selector)){
				if($val['type']=='radial'){
					if(!empty($val['start_color']) && !empty($val['end_color'])){
						$css = $selector.'{ background-image: radial-gradient('.$val['start_color'].', '.$val['end_color'].'); }';
						array_push( $device['md'], $css);
					}
				}else{
					if(!empty($val['start_color']) && !empty($val['end_color']) && !empty($val['angle'])){
						$css = $selector.'{ background-image: linear-gradient('.$val['angle'].'deg, '.$val['start_color'].', '.$val['end_color'].'); }';
						array_push( $device['md'], $css);
					}
				}
			}
			return $device;
		}
		/** Gradient End */
		
		/** Background Start */
		public static function nxtBackgroundCss($val, $selector, $device){
			if(!empty($val) && !empty($selector) && !empty($val['openBg'])){
				if($val['bgType']=='color'){
					return self::nxtColorCss($val['bgDefaultColor'], $selector, 'background-color', $device);
				}else if($val['bgType']=='gradient'){
					$css = $selector.'{ background-image: '.$val['bgGradient'].'; }';
					array_push( $device['md'], $css);					
				}else if($val['bgType']=='image'){
					$imgVal = $val['bgImage'];
					if(!empty($imgVal['url'])){
						$css = $selector.'{';
							$css .= 'background-image: url('.$imgVal['url'].');';
						if(!empty($val['bgimgPosition']) && $val['bgimgPosition']!='default'){
							$css .= 'background-position: '.$val['bgimgPosition'].';';
						}
						if(!empty($val['bgimgRepeat']) && $val['bgimgRepeat']!='default'){
							$css .= 'background-repeat: '.$val['bgimgRepeat'].';';
						}
						if(!empty($val['bgimgSize']) && $val['bgimgSize']!='default'){
							$css .= 'background-size: '.$val['bgimgSize'].';';
						}
						if(!empty($val['bgimgAttachment']) && $val['bgimgAttachment']!='default'){
							$css .= 'background-attachment: '.$val['bgimgAttachment'].';';
						}
						$css .= '}';
						array_push( $device['md'], $css);

						if(!empty($val['bgimgPositionTablet']) || !empty($val['bgimgRepeatTablet']) || !empty($val['bgimgSizeTablet'])){
							$cssTab = $selector.'{';
							if(!empty($val['bgimgPositionTablet']) && $val['bgimgPositionTablet']!='default'){
								$cssTab .= 'background-position: '.$val['bgimgPositionTablet'].';';
							}
							if(!empty($val['bgimgRepeatTablet']) && $val['bgimgRepeatTablet']!='default'){
								$cssTab .= 'background-repeat: '.$val['bgimgRepeatTablet'].';';
							}
							if(!empty($val['bgimgSizeTablet']) && $val['bgimgSizeTablet']!='default'){
								$cssTab .= 'background-size: '.$val['bgimgSizeTablet'].';';
							}
							$cssTab .= '}';
	
							array_push( $device['sm'], $cssTab);
						}
						if(!empty($val['bgimgPositionMobile']) || !empty($val['bgimgRepeatMobile']) || !empty($val['bgimgSizeMobile'])){
							$cssMob = $selector.'{';
							if(!empty($val['bgimgPositionMobile']) && $val['bgimgPositionMobile']!='default'){
								$cssMob .= 'background-position: '.$val['bgimgPositionMobile'].';';
							}
							if(!empty($val['bgimgRepeatMobile']) && $val['bgimgRepeatMobile']!='default'){
								$cssMob .= 'background-repeat: '.$val['bgimgRepeatMobile'].';';
							}
							if(!empty($val['bgimgSizeMobile']) && $val['bgimgSizeMobile']!='default'){
								$cssMob .= 'background-size: '.$val['bgimgSizeMobile'].';';
							}
							$cssMob .= '}';
	
							array_push( $device['sm'], $cssMob);
						}
					}
				}
			}
			return $device;
		}
		/** Background End */

		public static function objectReplace( $warp, $value ){
			$output = '';
			foreach($value as $sel) {
				$output .= $sel . ';';
			}
			return $warp . '{' . $output . '}';
		}
		public static function _device( $val, $selector ){
			$val = (array) $val;
			$data = [];
	
			$unit = '';
			if(!empty($val) && isset($val['unit']) && !empty($val['unit']) && $val['unit']!='c'){
				$unit = $val['unit'];
			}
			if ($val && isset($val['md']) && $val['md']!='') {
				$data['md'] =  str_replace('{{key}}', $val['md'] . $unit, $selector);
			}
			if ($val && isset($val['sm']) && $val['sm']!='') {
				$data['sm'] = str_replace('{{key}}', $val['sm'] . $unit, $selector);
			}
			if ($val && isset($val['xs']) && $val['xs']!='') {
				$data['xs'] = str_replace('{{key}}', $val['xs'] . $unit, $selector);
			}
			return $data;
		}
		public static function _push( $val, $data ){
		
			if (isset($val['md'])) {
				array_push( $data['md'], $val['md'] );
			}
			if (isset($val['sm'])) {
				array_push( $data['sm'], $val['sm'] );
			}
			if (isset($val['xs'])) {
				array_push( $data['xs'], $val['xs'] );
			}
			return $data;
		}

		public static function nxtMakeCss($deviceVal){
			$Make_CSS = '';
			if ( !empty($deviceVal['md']) ) {
				$Make_CSS .= join("",$deviceVal['md']);
			}
			if ( !empty($deviceVal['sm']) ) {
				$Make_CSS .= '@media (max-width: 1024px) {' . join("",$deviceVal['sm']) . '}';
			}
			if ( !empty($deviceVal['xs']) ) {
				$Make_CSS .= '@media (max-width: 767px) {' . join("",$deviceVal['xs']) . '}';
			}

			return $Make_CSS ;
		}

		public static function _customDevice( $val, $selector ){
			$data = [];
			
			if ( $val && isset($val['md']) ) {
				if(gettype($val['md']) == 'object' || gettype($val['md']) == 'array' ){
					$val_md = is_array($val['md']) ? '' : $val['md'];
					$selectorReplaceSpl = explode(":", str_replace('{{key}}', $val_md, $selector) );
					//$selectorReplaceSpl2 = array_slice($selectorReplaceSpl, 2);
					$cssSyntax = $selectorReplaceSpl[0];
					$top = isset($val['md']['top']) ? $val['md']['top'] : '';
					$right = isset($val['md']['right']) ? $val['md']['right'] : '';
					$bottom = isset($val['md']['bottom']) ? $val['md']['bottom'] : '';
					$left = isset($val['md']['left']) ? $val['md']['left'] : '';
					if($top!=='' || $right!=='' || $bottom!=='' || $left!==''){
						$data['md'] = $cssSyntax . ':' . ($top ? $top : '0') . $val['unit'] . ' ' . ($right ? $right : '0') . $val['unit'] . ' ' . ($bottom ? $bottom : '0') . $val['unit'] . ' ' . ($left ? $left : '0') . $val['unit'];
					}
				}
			}
			if ( $val && isset($val['sm']) ) {
				if( gettype($val['sm']) == 'object' || gettype($val['sm']) == 'array' ){
					$val_sm = is_array($val['sm']) ? '' : $val['sm'];
					$selectorReplaceSpl3 = explode(":", str_replace('{{key}}', $val_sm, $selector) );
					//$selector$replace$spl4 = _slicedToArray(_selector$replace$spl3, 2),
					$cssSyntax = $selectorReplaceSpl3[0];
					$top = isset($val['sm']['top']) ? $val['sm']['top'] : '';
					$right = isset($val['sm']['right']) ? $val['sm']['right'] : '';
					$bottom = isset($val['sm']['bottom']) ? $val['sm']['bottom'] : '';
					$left = isset($val['sm']['left']) ? $val['sm']['left'] : '';
					if($top!=='' || $right!=='' || $bottom!=='' || $left!==''){
						$data['sm'] = $cssSyntax . ':' . ($top ? $top : '0') . $val['unit'] . ' ' . ($right ? $right : '0') . $val['unit'] . ' ' . ($bottom ? $bottom : '0') . $val['unit'] . ' ' . ($left ? $left : '0') . $val['unit'];
					}
				}
			}
			if ( $val && isset($val['xs']) ) {
				if( gettype($val['xs']) == 'object' || gettype($val['xs']) == 'array' ){
					$val_xs = is_array($val['xs']) ? '' : $val['xs'];
					
					$selectorReplaceSpl3 = explode(":", str_replace('{{key}}', $val_xs, $selector) );
					//$selector$replace$spl4 = _slicedToArray(_selector$replace$spl3, 2),
					$cssSyntax = $selectorReplaceSpl3[0];
					$top = isset($val['xs']['top']) ? $val['xs']['top'] : '';
					$right = isset($val['xs']['right']) ? $val['xs']['right'] : '';
					$bottom = isset($val['xs']['bottom']) ? $val['xs']['bottom'] : '';
					$left = isset($val['xs']['left']) ? $val['xs']['left'] : '';
					if($top!=='' || $right!=='' || $bottom!=='' || $left!==''){
						$data['xs'] = $cssSyntax . ':' . ($top ? $top : '0') . $val['unit'] . ' ' . ($right ? $right : '0') . $val['unit'] . ' ' . ($bottom ? $bottom : '0') . $val['unit'] . ' ' . ($left ? $left : '0') . $val['unit'];
					}
				}
			}
			
			return $data;
		}
	}
	Nxt_custom_Fields_Components::get_instance();
?>