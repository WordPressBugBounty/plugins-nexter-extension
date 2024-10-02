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

		/** Responsive Device */
		public static function responsive_opt($mainComp, $name, $type){
			$output = '';
			$output .='<div class="nxt-device active-md"><button onclick="nxtDeviceVal(`md`,`'.esc_attr($mainComp).'`, `'.esc_attr($name).'`, `'.esc_attr($type).'`)" title="Desktop" class="nxt-device-desktop active"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M10 1.5H2a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-5a1 1 0 0 0-1-1ZM4 10.5h4M6 8.5v2" stroke="#000" stroke-width=".5" stroke-linecap="round" stroke-linejoin="round"/></svg></button><button onclick="nxtDeviceVal(`sm`,`'.esc_attr($mainComp).'`, `'.esc_attr($name).'`, `'.esc_attr($type).'`)" title="Tablet" class="nxt-device-tablet"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M1 7.5v2.1c0 .9 1 .9 1.43.9h7.14c.43 0 1.43 0 1.43-.9V7.5m-10 0V2.4c0-.9.5-.9 1.43-.9h7.14c.93 0 1.43 0 1.43.9v5.1m-10 0h10" stroke="#000" stroke-width=".5" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 9h0" stroke="#000" stroke-linecap="round" stroke-linejoin="round"/></svg></button><button onclick="nxtDeviceVal(`xs`,`'.esc_attr($mainComp).'`, `'.esc_attr($name).'`, `'.esc_attr($type).'`)" title="Phone" class="nxt-device-mobile"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 8v2a1 1 0 0 0 1 1h5a1 1 0 0 0 1-1V8m-7 0V2a1 1 0 0 1 1-1h5a1 1 0 0 1 1 1v6m-7 0h7" stroke="#000" stroke-width=".5" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 9.5h0" stroke="#000" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div>';

			return $output;
		}

		/** Unit (px, %, em) */
		public static function unit_opt($args, $value){
			$output = '';
			$output .= '<div class="nxt-unit-btn-group nxt-ml-auto">';
			if(!empty($args)){
				foreach ( $args['unit'] as $index => $val ) :
					$active= '';
					if($val==$value['unit']){
						$active = 'active';
					}
					$output .='<button onclick="nxtChgUnit(this,`'.esc_attr($args['mainComp']).'`, `'.esc_attr($args['name']).'`, `'.esc_attr($val).'`)" class="'.esc_attr($active).'">'.wp_kses_post($val).'</button>';
				endforeach;
			}
			$output .= '</div>';

			return $output;
		}

		/** Text Field */
		public static function text_field( $args= [], $value =[] ){
			$output = $new_val = '';
			if( !empty($args) && isset($args['name']) ){
				$notamain = (!empty($args['mainComp'])) ? 'not-a-main' : '';
				if(!empty($value) && !empty($value[$args['name']]) && isset($value[$args['name']])){
					$new_val =  $value[$args['name']];
				}else if(isset($args['default'])){
					$new_val =  $args['default'];
				}
				$output .='<div class="nxt-field nxt-field-text '.esc_attr($notamain).'" data-name="'.esc_attr($args['name']).'" data-value="'.esc_attr($new_val).'">';
					if( !empty($args['label']) ){
						$output .= '<label>'.wp_kses_post($args['label']).'</label>';
					}
					$type = isset($args['type']) ? $args['type'] : 'text';

					$output .= '<input placeholder="Please paste logo link here" type="'.esc_attr($type).'" data-main="'.esc_attr($args['mainComp']).'" name="'.esc_attr($args['name']).'" value="'.esc_attr($new_val).'" oninput="nxtText(this)"/>';
				$output .='</div>';
			}
			return $output;
		}

		/** Toggle Switch */
		public static function toggle_field( $args= [], $value = false ){
			$output = '';
			if( !empty($args) && isset($args['name']) ){
				$notamain = (!empty($args['mainComp'])) ? 'not-a-main' : '';

				$data_attr = false;
				$data_attr = 'data-attr="'.htmlspecialchars(json_encode($data_attr, true), ENT_QUOTES, 'UTF-8').'"';
				$output .='<div class="nxt-field nxt-field-toggle '.esc_attr($notamain).'" '.$data_attr.' data-name="'.esc_attr($args['name']).'">';
					if( !empty($args['label']) ){
						$output .= '<label>'.wp_kses_post($args['label']).'</label>';
					}
					$output .='<span class="nxt-form-toggle" onclick="nxtToggle(this)" data-main="'.esc_attr($args['mainComp']).'" name="'.esc_attr($args['name']).'"><input class="nxt-form-toggle-input" id="inspector-toggle-control-0" type="checkbox"><span class="nxt-form-toggle-track"></span><span class="nxt-form-toggle-thumb"></span></span>';
				$output .='</div>';
			}
			return $output;
		}

		/** Select Field */
		public static function select_field( $args= [], $value = [] ){
			$output = $data_attr = '';
			if( !empty($args) && isset($args['name']) ){
				$new_val = (!empty($value) && !empty($value[$args['name']])) ? $value[$args['name']] : '';
				
				$data_attr .= ' data-name="'.esc_attr($args['name']).'"';
				if(empty($args['mainComp'])){
					$data_attr .= ' data-value="'.esc_attr($new_val).'"';
				}
				$wrap_class = '';
				if(isset($args['inline']) && !empty($args['inline'])){
					$wrap_class .= ' nxt-inline-block';
				}
				$wrap_class .= (!empty($args['mainComp'])) ? ' not-a-main' : '';

				$output .='<div class="nxt-field nxt-field-select '.esc_attr($wrap_class).'" '.$data_attr.'>';
					if( !empty($args['label']) ){
						$output .= '<label>'.wp_kses_post($args['label']).'</label>';
					}
					$output .= '<div class="nxt-popup-select"><select onchange="nxtSelect(this)" data-main="'.esc_attr($args['mainComp']).'" data-imgComp="'.esc_attr($args['imgComp']).'" name="'.esc_attr($args['name']).'">';
					if(!empty($args['options'])){
						foreach ( $args['options'] as $index => $val ) :
							$selected = ($index == $new_val) ? 'selected' : '';
							$output .= '<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.wp_kses_post($val).'</option>';
						endforeach;
					}
					$output .= '</select></div>';
				$output .='</div>';
			}
			return $output;
		}

		/** Dimension Field */
		public static function dimension_field( $args= [], $value =[] ){
			$output = $data_attr = ''; $new_val = [];
			if( !empty($args) && isset($args['name']) ){
				$notamain = (!empty($args['mainComp'])) ? 'not-a-main' : '';
				$defUnit = (!empty($args['default']['unit'])) ? $args['default']['unit'] : '';
				
				if(!empty($value) && !empty($value[$args['name']])){
					$new_val = $value[$args['name']];
				}else{
					$allData = ['top' => '', 'right' => '', 'bottom' => '', 'left' => ''];
					$new_val = ['md' => $allData, 'sm' => $allData, 'xs' => $allData, 'unit'=> $defUnit];
				}
				
				if(empty($args['mainComp'])){
					$data_attr = 'data-attr="'.htmlspecialchars(json_encode($new_val, true), ENT_QUOTES, 'UTF-8').'"';
				}

				$output .='<div class="nxt-field nxt-field-dimension nxt-d-flex '.esc_attr($notamain).'" '.$data_attr.' data-name="'.esc_attr($args['name']).'">';
					$output .='<div class="nxt-d-flex">';
						if( !empty($args['label']) ){
							$output .= '<label>'.wp_kses_post($args['label']).'</label>';
						}
						$output .= self::responsive_opt($args['mainComp'], $args['name'], 'dimension');
						if(!empty($args['unit'])){
							$output .= self::unit_opt($args, $new_val);
						}
					$output .='</div>';
					$output .='<div class="nxt-field-child">';
						$output .='<div class="nxt-dimension-input-group">';
							$min = isset($args['min']) ? esc_attr($args['min']) : '';
							$max = isset($args['max']) ? esc_attr($args['max']) : '';
							$step = isset($args['step']) ? esc_attr($args['step']) : '';
							$dimArray = ['top','right','bottom','left'];
							foreach($dimArray as $index => $name) :
								$output .='<span>';
									$output .='<input type="number" placeholder="'.esc_attr($name).'" data-main="'.esc_attr($args['mainComp']).'" name="'.esc_attr($args['name']).'" min="'.esc_attr($min).'" max="'.esc_attr($max).'" step="'.esc_attr($step).'" value="'.esc_attr($new_val['md'][$name]).'" oninput="nxtDimension(this)" data-val="'.esc_attr($name).'">';
									// $output .='<span>'.esc_html($name).'</span>';
								$output .='</span>';
							endforeach;
							$output .='<button class="nxt-dimension-btn active" onclick="nxtDvalAll(this, `'.esc_attr($args['name']).'`, `'.esc_attr($args['mainComp']).'`)">';
								$output .='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M2.3 12.54c0-.84.33-1.65.92-2.24L5 8.53A1 1 0 1 0 3.58 7.1L1.8 8.88a5.17 5.17 0 0 0 7.3 7.3l1.78-1.76a1 1 0 1 0-1.42-1.41L7.7 14.77a3.17 3.17 0 0 1-5.4-2.23ZM7.1 3.58a1 1 0 0 0 1.42 1.41l1.77-1.77a3.17 3.17 0 1 1 4.47 4.48l-1.76 1.77a1 1 0 1 0 1.41 1.42l1.77-1.77a5.17 5.17 0 1 0-7.3-7.3L7.1 3.57Zm-1.17 7.07a1 1 0 0 0 1.41 1.41l4.71-4.71a1 1 0 0 0-1.41-1.41l-4.71 4.7Z" fill="#000"/></svg>';
							$output .='</button>';
						$output .='</div>';
					$output .='</div>';
				$output .='</div>';
			}
			return $output;
		}

		/** Range Field */
		public static function range_field( $args, $value = [] ){
			$output = $data_attr = $intialVal = ''; $new_val = []; $responTrue = false;
			if( !empty($args) && isset($args['name']) ){
				if(!empty($value) && !empty($value[$args['name']])){
					$new_val = $value[$args['name']];
				}else{
					if($args['responsive']){
						$new_val = [ 'md' => '', 'sm' => '', 'xs' => '', 'unit' => 'px' ];
					}else{
						$new_val = '';
					}
				}
				if(!empty($args['responsive'])){
					$responTrue = true;
					$intialVal = $new_val['md'];
				}else{
					$responTrue = false;
					$intialVal = $new_val;
				}
				$notamain = (!empty($args['mainComp'])) ? 'not-a-main' : '';

				$data_attr = 'data-attr="'.htmlspecialchars(json_encode($new_val, true), ENT_QUOTES, 'UTF-8').'"';
				$output .='<div class="nxt-field nxt-field-range '.esc_attr($notamain).'" '.$data_attr.'  data-name="'.esc_attr($args['name']).'" >';
					$output .='<div class="nxt-d-flex">';
						if( !empty($args['label']) ){
							$output .= '<label>'.wp_kses_post($args['label']).'</label>';
						}
						if(!empty($args['responsive'])){
							$output .= self::responsive_opt($args['mainComp'], $args['name'], 'range');
						}
						if(!empty($args['unit'])){
							$output .= self::unit_opt($args, $new_val);
						}
					$output .='</div>';
					$min = isset($args['min']) ? esc_attr($args['min']) : '';
					$max = isset($args['max']) ? esc_attr($args['max']) : '';
					$step = isset($args['step']) ? esc_attr($args['step']) : '';
					$output .='<div class="nxt-field-child nxt-d-flex">';
						$output .='<div class="nxt-input-range">';
							$output .='<input oninput="nxtRange(this,`'.esc_attr($args['mainComp']).'`,`'.esc_attr($responTrue).'`)" type="range" data-main="'.esc_attr($args['mainComp']).'" name="'.esc_attr($args['name']).'" min="'.esc_attr($min).'" max="'.esc_attr($max).'" step="'.esc_attr($step).'" value="'.esc_attr($intialVal).'">';
							$output .='<input oninput="nxtRange(this,`'.esc_attr($args['mainComp']).'`,`'.esc_attr($responTrue).'`)" type="number" data-main="'.esc_attr($args['mainComp']).'" name="'.esc_attr($args['name']).'" min="'.esc_attr($min).'" max="'.esc_attr($max).'" step="'.esc_attr($step).'" value="'.esc_attr($intialVal).'">';
						$output .='</div>';
					$output .='</div>';
				$output .='</div>';
			}
			return $output;
		}

		/** Border Field */
		public static function border_field( $args= [], $value =[] ){
			$output = ''; $new_val = [];
			if( !empty($args) && isset($args['name']) ){
				if(!empty($value) && !empty($value[$args['name']])){
					$new_val = $value[$args['name']];
				}else{
					$allwidthData = ['top' => '', 'right' => '', 'bottom' => '', 'left' => ''];
					$width_attr = ['lock' => true,'md' => $allwidthData, 'sm' => $allwidthData, 'xs' => $allwidthData, 'unit' => 'px'];
					$new_val = [
						'open' => 0,
						'border_type' => '',
						'border_color' => '',
						'border_width' => $width_attr
					];
				}

				$resetAct = (!empty($new_val) && !empty($new_val['open'])) ? 'active' : '';

				$data_attr = 'data-attr="'.htmlspecialchars(json_encode($new_val, true), ENT_QUOTES, 'UTF-8').'"';

				$output .='<div class="nxt-field nxt-field-border" data-name="'.esc_attr($args['name']).'" '.$data_attr.' name="'.esc_attr($args['name']).'">';
					if( !empty($args['label']) ){
						$output .= '<label>'.wp_kses_post($args['label']).'</label>';
					}
					$output .='<div class="nxt-reset-btn">';
						$output .='<span class="nxt-open-clear '.esc_attr($resetAct).'" role="button" onclick="resetVal(this,`'.esc_attr($args['name']).'`)">';
							$output .='<svg xmlns="http://www.w3.org/2000/svg" width="11.13" height="13.98" viewBox="0 0 14.13 16.98"><path d="M6.41 0v1.47A7.05 7.05 0 0 0 2 13.41l1.78-1.59A4.68 4.68 0 0 1 6.4 3.86v1.37L10.6 2.6Zm5.72 3.57-1.76 1.6a4.68 4.68 0 0 1-2.66 7.94v-1.35l-4.18 2.61 4.18 2.61v-1.47a7.05 7.05 0 0 0 4.42-11.94Z" fill="#888"/></svg>';
						$output .='</span>';
					$output .='</div>';
					$output .='<div class="nxt-field-button-list nxt-ml-auto">';
						$dimArray = ['solid','dotted','dashed','double'];
						foreach($dimArray as $index => $type) :
							$activated = '';
							if($type == $new_val['border_type']){
								$activated = 'active';
							}
							$output .='<button class="nxt-button border-style '.esc_attr($activated).'" onclick="nxtBorder(this,`'.esc_attr($args['name']).'`)" data-name="type" label="'.esc_attr($type).'" name="border_type">';
								$output .='<span class="nxt-field-border-type nxt-field-border-type-'.esc_attr($type).'"></span>';
							$output .='</button>';
						endforeach;
					$output .='</div>';
					$output .='<div class="nxt-border-popup nxt-mt-10 '.esc_attr($resetAct).'">';
						$output .=self::color_field(['label' => 'Border Color', 'name'=> 'border_color', 'gradComp' => '', 'mainComp'=>$args['name']], $new_val);
						$output .=self::dimension_field(['label' => 'Border width', 'mainComp' => $args['name'], 'name'=> 'border_width', 'min'=> 1, 'max'=> 100, 'step'=> 1, 'default'=> ['unit'=> 'px'], 'unit'=> ['px','%','em'] ], $new_val);
					$output .='</div>';
				$output .='</div>';
			}
			return $output;
		}

		/** BoxShadow Field */
		public static function boxshadow_field( $args= [], $value = [] ){
			$output = ''; $new_val = [];
			if( !empty($args) && isset($args['name']) ){
				$shadowType = (!empty($value['shadowtype'])) ? $value['shadowtype'] : 'box';
				if(!empty($value) && !empty($value[$args['name']])){
					$new_val = $value[$args['name']];
				}else{
					$new_val = [ 'shadowtype' => $shadowType, 'x' => '', 'y' => '', 'blur' => '', 'spread' => '', 'color' => '', 'type' => 'outset' ];
				}

				$data_attr = 'data-attr="'.htmlspecialchars(json_encode($new_val, true), ENT_QUOTES, 'UTF-8').'"';
				$output .='<div class="nxt-field nxt-field-boxshadow nxt-d-flex nxt-inline-block" data-name="'.esc_attr($args['name']).'" '.$data_attr.'>';
					if( !empty($args['label']) ){
						$output .= '<label class="nxt-mb-0">'.wp_kses_post($args['label']).'</label>';
					}
					$output .= '<div class="nxt-field-button-list nxt-ml-auto">';
						$output .='<button class="nxt-bs-button '.($new_val['type']=='inset' ? 'active' : '').'" name="type" onclick="insetBtn(this,`'.esc_attr($args['name']).'`)" value="inset">'.esc_html__('Inset', 'nexter-ext').'</button>';
						$output .='<button class="nxt-bs-button '.($new_val['type']=='outset' ? 'active' : '').'" name="type" onclick="insetBtn(this,`'.esc_attr($args['name']).'`)" value="outset">'.esc_html__('Outset', 'nexter-ext').'</button>';
					$output .= '</div>';
					
					$output .='<div class="nxt-field nxt-d-flex nxt-mt-10 nxt-align-justified boxshadow-content">';
						$type = isset($args['type']) ? esc_attr($args['type']) : 'number';
						$min = isset($args['min']) ? esc_attr($args['min']) : '';
						$max = isset($args['max']) ? esc_attr($args['max']) : '';
						$step = isset($args['step']) ? esc_attr($args['step']) : '';
						$bsArray = ['x','y','blur','spread'];
						foreach($bsArray as $index => $name) :
							$output .='<div class="nxt-base-control-field">';
								$output .='<input type="'.esc_attr($type).'" placeholder="'.esc_attr($name).'" onchange="bShadowdmnsn(this,`'.esc_attr($args['name']).'`)" class="nxt-text-control-input" id="" name="'.esc_attr($name).'" min="'.esc_attr($min).'" max="'.esc_attr($max).'" step="'.esc_attr($step).'" value="'.esc_attr($new_val[$name]).'">';
							$output .='</div>';
						endforeach;
					$output .='</div>';
					$output .= self::color_field(['label' => 'Shadow Color', 'name'=> 'color', 'gradComp' => '', 'mainComp'=>$args['name']], $new_val );
				$output .='</div>';
			}
			return $output;
		}

		/** Typography Field */
		public static function typography_field( $args= [], $value = [] ){
			$output = ''; $new_val = [];
			if( !empty($args) && isset($args['name']) ){
				if(!empty($value) && !empty($value[$args['name']])){
					$new_val = $value[$args['name']];
				}else{
					$resp = ['md' => '', 'sm' => '', 'xs' => '', 'unit'=> 'px'];
					$new_val = [
						'open' => 0,
						'font_size' => $resp,
						'font_family' => ['family'=> '', 'type'=> ''],
						'font_weight' => '',
						'letter_spacing' => $resp,
						'line_height' => $resp,
						'font_style' => '',
						'text_transform' => '',
						'text_decoration' => ''
					];
				}

				$resetAct = (!empty($new_val) && !empty($new_val['open'])) ? 'active' : '';

				$data_attr = 'data-attr="'.htmlspecialchars(json_encode($new_val, true), ENT_QUOTES, 'UTF-8').'"';

				$output .='<div class="nxt-field nxt-d-flex nxt-field-typography nxt-inline-block" name="'.esc_attr($args['name']).'" data-name="'.esc_attr($args['name']).'" '.$data_attr.'>';
					if( !empty($args['label']) ){
						$output .= '<label class="nxt-mb-0">'.wp_kses_post($args['label']).'</label>';
					}
					$output .='<div class="nxt-reset-btn">';
						$output .='<span class="nxt-open-clear '.esc_attr($resetAct).'" role="button" onclick="resetVal(this,`'.esc_attr($args['name']).'`)">';
							$output .='<svg xmlns="http://www.w3.org/2000/svg" width="11.13" height="13.98" viewBox="0 0 14.13 16.98"><path d="M6.41 0v1.47A7.05 7.05 0 0 0 2 13.41l1.78-1.59A4.68 4.68 0 0 1 6.4 3.86v1.37L10.6 2.6Zm5.72 3.57-1.76 1.6a4.68 4.68 0 0 1-2.66 7.94v-1.35l-4.18 2.61 4.18 2.61v-1.47a7.05 7.05 0 0 0 4.42-11.94Z" fill="#888"/></svg>';
						$output .='</span>';
					$output .='</div>';
					$output .='<div class="nxt-flex">';
						$output .='<div class="nxt-dropdown nxt-ml-auto" tabindex="">';
							$output .='<button class="nxt-typo-settings '.esc_attr($resetAct).'" onclick="nxtTypo(this, `'.esc_attr($args['name']).'`)">';
								$output .='<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 19 19" fill="none"><path d="M11.25 5.25a.83.83 0 0 0 0 1.17l1.33 1.33a.83.83 0 0 0 1.17 0l3.14-3.14a5 5 0 0 1-6.61 6.61l-5.76 5.76a1.77 1.77 0 0 1-2.5-2.5l5.76-5.76a5 5 0 0 1 6.61-6.61l-3.13 3.13h-.01Z" stroke="#727272" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
							$output .='</button>';
						$output .='</div>';
					$output .='</div>';
					$output .= self::typo_popup($args, $new_val);
				$output .='</div>';
			}
			return $output;
		}
		/** Typography-popup Field */
		public static function typo_popup( $args, $new_val ){
			$family = (!empty($new_val['font_family']['family']) ? $new_val['font_family']['family'] : 'Select');
			$weight = (!empty($new_val['font_weight']) ? $new_val['font_weight'] : 'Select');
			$output = '';
			$output .='<div class="nxt-popover-slot nxt-popover-style nxt-typo-popup">';
				$output .='<div class="nxt-popover-content">';
					$output .='<div class="nxt-typography-advanced">';
						$output .='<div class="nxt-typography-font-family-options">';
							$output .='<div class="nxt-fonts-weight-inner">';
								$output .='<div class="nxt-font-family-opt">';
									$output .='<label class="nxt-typography-font-family-label">'.esc_html__('Font Family', 'nexter-ext').'</label>';
									$output .='<div class="nxt-font-family-wrap" onclick="nxtFFList(this, `font_family`, `'.esc_attr($args['name']).'`)"><span class="nxt-font-family-input">';
									$output .='<input type="text" class="nxt-font-filter" placeholder="'.esc_attr($family).'" oninput="nxtFFSearch(this)" value="">';
									// $output .='<span type="text" class="nxt-font-filter">'.wp_kses_post($family).'</span>';
									$output .='<span class="nxt-font-updown-icon"><svg xmlns="http://www.w3.org/2000/svg" width="9.4" height="6.1" viewBox="0 0 9.4 6.1"><path d="M6.7,8.1,2,3.4,3.4,2,6.7,5.3,10,2l1.4,1.4Z" transform="translate(-2 -2)"></path></svg></span></span></div>';
								$output .='</div>';
								$output .='<div class="nxt-font-weight-opt">';
									$output .='<label class="nxt-typography-font-weight-label">'.esc_html__('Font Weight','nexter-ext').'</label>';
									$output .='<div class="nxt-font-weight-wrap" onclick="nxtFWList(this, `font_weight`, `'.esc_attr($args['name']).'`)"><div class="nxt-font-weight-val">'.wp_kses_post($weight).'</div><span class="nxt-font-updown-icon"><svg xmlns="http://www.w3.org/2000/svg" width="9.4" height="6.1" viewBox="0 0 9.4 6.1"><path d="M6.7,8.1,2,3.4,3.4,2,6.7,5.3,10,2l1.4,1.4Z" transform="translate(-2 -2)"></path></svg></span></div>';
								$output .='</div>';
							$output .='</div>';
						$output .='</div>';
					$output .='</div>';

					$output .= self::range_field(['label' => 'Font Size','default'=> ['md'=>'','sm'=> '','xs'=> '', 'unit'=> 'px'], 'mainComp'=> $args['name'], 'name'=> 'font_size', 'responsive'=> $args['responsive'], 'unit'=> ['px', '%', 'em']], $new_val );
					$output .= self::range_field(['label' => 'Line Height','default'=> ['md'=>'','sm'=> '','xs'=> '', 'unit'=> 'px'], 'mainComp'=> $args['name'], 'name'=> 'line_height', 'responsive'=> $args['responsive'], 'unit'=> ['px', '%', 'em']], $new_val );
					$output .= self::range_field(['label' => 'Letter Spacing','default'=> ['md'=>'','sm'=> '','xs'=> '', 'unit'=> 'px'], 'mainComp'=> $args['name'], 'name'=> 'letter_spacing', 'responsive'=> $args['responsive'], 'unit'=> ['px', '%', 'em']], $new_val );
					$output .= self::select_field(['label' => 'Font Style', 'mainComp'=> $args['name'], 'imgComp' => '', 'name'=> 'font_style', 'options' => ['default'=> 'Default','normal'=> 'Normal','italic'=> 'Italic','oblique'=> 'Oblique']], $new_val );
					$output .= self::select_field(['label' => 'Text Transform', 'mainComp'=> $args['name'], 'imgComp' => '', 'name'=> 'text_transform', 'options' => ['none'=> 'None','capitalize'=> 'Capitalize','uppercase'=> 'Uppercase','lowercase'=> 'Lowercase']], $new_val );
					$output .= self::select_field(['label' => 'Text Decoration', 'mainComp'=> $args['name'], 'imgComp' => '', 'name'=> 'text_decoration', 'options' => ['default'=> 'Default','none'=> 'None','underline'=> 'Underline','overline'=> 'Overline','line-through'=> 'Line Through']], $new_val );
				$output .='</div>';
			$output .='</div>';
			return $output;
		}


		/** Color Field */
		public static function color_field( $args= [], $value = [] ){
			$output = '';
			if( !empty($args) && isset($args['name']) ){
				$notamain = (!empty($args['mainComp']) || !empty($args['mainComp'])) ? 'not-a-main' : '';
				
				$output .='<div class="nxt-field nxt-field-color nxt-inline-block '.esc_attr($notamain).'">';
					if( !empty($args['label']) ){
						$output .= '<label class="nxt-mb-0">'.wp_kses_post($args['label']).'</label>';
					}
					$type = isset($args['type']) ? esc_attr($args['type']) : 'color';
					$new_val = $value!='' && isset($value[$args['name']]) ? $value[$args['name']] : (isset($args['default']) ? $args['default'] : '');
					$mainComp = isset($args['mainComp']) ? esc_attr($args['mainComp']) : '';
					$gradComp = isset($args['gradComp']) ? esc_attr($args['gradComp']) : '';
					$output .='<div class="nxt-flex">';
						$output .='<div class="nxt-dropdown nxt-ml-auto" tabindex="">';
							$output .='<span class="nxt-color-picker-container">';
								$output .='<input data-alpha-enabled="true" data-gradcomp="'.esc_attr($gradComp).'" data-maincomp="'.esc_attr($mainComp).'" name="'.esc_attr($args['name']).'" class="nxt-color-picker" value="'.esc_attr($new_val).'" area-expanded=""> </input>';
							$output .='</span>';
						$output .='</div>';
					$output .='</div>';
				$output .='</div>';
			}
			return $output;
		}

		/** Gradient Field */
		public static function gradient_field( $args= [], $value =[] ){
			$output = '';
			if( !empty($args) && isset($args['name']) ){
				$notamain = (!empty($args['mainComp'])) ? 'not-a-main' : '';
				$new_val = (!empty($value) && !empty($value[$args['name']])) ? $value[$args['name']] : ['start_color' => '', 'end_color' => '', 'type' => 'linear', 'angle' => '90'];
				$data_attr = '';
				if(empty($args['mainComp'])){
					$data_attr = 'data-attr="'.htmlspecialchars(json_encode($new_val, true), ENT_QUOTES, 'UTF-8').'"';
				}
				$gradType = ['linear' => 'Linear', 'radial' => 'Radial'];
				
				$output .='<div class="nxt-field nxt-field-gradient nxt-inline-block '.esc_attr($notamain).'" '.$data_attr.'  data-name="'.esc_attr($args['name']).'">';
					if( !empty($args['label']) ){
						$output .= '<label class="nxt-mb-10">'.wp_kses_post($args['label']).'</label>';
					}
					$output .= self::color_field(['label' => 'Start Color', 'name'=> 'start_color', 'gradComp'=> $args['name'], 'mainComp'=> $args['mainComp']], $new_val );
					$output .= self::color_field(['label' => 'End Color', 'name'=> 'end_color', 'gradComp'=> $args['name'], 'mainComp'=> $args['mainComp']], $new_val );
					$output .='<div class="nxt-field nxt-field-gradient-type nxt-inline-block">';
						$output .= '<label>'.esc_html__('Type', 'nexter-ext').'</label>';
						$output .= '<div class="nxt-popup-select"><select onchange="nxtGradtype(this, `'.esc_attr($args['name']).'`, `'.esc_attr($args['mainComp']).'`)">';
							foreach ( $gradType as $index => $val ) :
								$selected = '';
								if($index == $new_val['type']){
									$selected = 'selected';
								}
								$output .= '<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.wp_kses_post($val).'</option>';
							endforeach;
						$output .= '</select></div>';
					$output .='</div>';
					$output .='<div class="nxt-field nxt-field-gradient-angle nxt-inline-block">';
						$output .= '<label>'.esc_html__('Angle', 'nexter-ext').'</label>';
						$output .= '<input type="number" data-main="'.esc_attr($args['mainComp']).'" name="angle" value="'.esc_attr($new_val['angle']).'" min="0" max="360" step="1" oninput="nxtGradAngle(this, `'.esc_attr($args['name']).'`, `'.esc_attr($args['mainComp']).'`)"/>';
					$output .='</div>';
				$output .='</div>';
			}
			return $output;
		}

		/** Image Field */
		public static function image_field( $args= [], $value = [] ){
			$output = $data_attr = ''; $new_val = [];
			if( !empty($args) && isset($args['name']) ){
				$notamain = (!empty($args['mainComp'])) ? 'not-a-main' : '';
				if(!empty($value) && !empty($value[$args['name']])){
					$new_val = $value[$args['name']];
				}else{
					$new_val = [ 'id' => '', 'url' => '', 'title' => '' ];
				}

				if(empty($args['mainComp'])){
					$data_attr = 'data-attr="'.htmlspecialchars(json_encode($new_val, true), ENT_QUOTES, 'UTF-8').'"';
				}
				$mainComp = isset($args['mainComp']) ? $args['mainComp'] : '';
				$output .='<div class="nxt-field nxt-field-image nxt-inline-block '.esc_attr($notamain).'" data-maincomp="'.esc_attr($mainComp).'" data-name="'.esc_attr($args['name']).'" '.$data_attr.'>';
					$output .='<div class="nxt-d-flex">';
					if( !empty($args['label']) ){
						$output .= '<label class="nxt-mb-0">'.wp_kses_post($args['label']).'</label>';
					}
					$output .='</div>';

					$output .='<div class="nxt-placeholder-image nxt-wp-log-media nxt-dropdown" tabindex="">';
						if(!empty($new_val['url'])){
							$output .='<div href="#" class="nxt-wp-login-logo-img-upl">';
								$output .='<img id="image-preview" src="'.esc_url($new_val['url']).'">';
							$output .='</div>';
							$output .='<a href="#" class="nxt-media-remove nxt-wp-login-logo-img-rmv">';
								$output .='<svg xmlns="http://www.w3.org/2000/svg" width="11.8" height="13.8" viewBox="0 0 8.89 10.16"><path id="Path_303" data-name="Path 303" d="M64.44 135.24a1.27 1.27 0 0 0 1.28 1.27h5.08a1.27 1.27 0 0 0 1.27-1.27v-6.35h-7.63Z" transform="translate(-63.8 -126.35)"/><path id="Path_304" data-name="Path 304" d="M37.94.64V0H35.4v.64h-3.18V1.9h8.9V.64Z" transform="translate(-32.22)"/></svg>';
							$output .='</a>';
						}else{
							$output .='<div href="#" class="nxt-wp-login-logo-img-upl">';
								$output .='<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M1 2.5c0-.28.22-.5.5-.5h1c.28 0 .5.22.5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1Zm20 14.63a1 1 0 0 1 1.51-.86l7.53 4.48a1 1 0 0 1-.21 1.81l-2.33.73 2.19 3.78a1 1 0 0 1-.35 1.36l-1.65.99a1 1 0 0 1-1.38-.36l-2.18-3.77-1.46 1.32a1 1 0 0 1-1.67-.74v-8.74Zm8.53 4.48L22 17.13v8.74l1.46-1.32a1 1 0 0 1 1.53.24l2.19 3.77 1.64-.99-2.19-3.78a1 1 0 0 1 .57-1.45l2.33-.73ZM6.5 2a.5.5 0 0 0-.5.5v1c0 .28.22.5.5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1Zm4.5.5c0-.28.22-.5.5-.5h1c.28 0 .5.22.5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1Zm5.5-.5a.5.5 0 0 0-.5.5v1c0 .28.22.5.5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1ZM16 7.5c0-.28.22-.5.5-.5h1c.28 0 .5.22.5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1ZM11.5 10a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h7v-1h-7a1 1 0 0 1-1-1V12a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v3.5h1V12a2 2 0 0 0-2-2h-14ZM1 7.5c0-.28.22-.5.5-.5h1c.28 0 .5.22.5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1Zm.5 4.5a.5.5 0 0 0-.5.5v1c0 .28.22.5.5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1ZM1 17.5c0-.28.22-.5.5-.5h1c.28 0 .5.22.5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1Zm5.5-.5a.5.5 0 0 0-.5.5v1c0 .28.22.5.5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1Z" fill="#A1A1A1"/></svg>';
								$output .='<span>'.esc_html__('Drag and drop to upload your super awesome Image','nexter-ext').'</span>';
							$output .='</div>';
							$output .='<a href="#" class="nxt-media-remove nxt-wp-login-logo-img-rmv" style="display: none">';
								$output .='<svg xmlns="http://www.w3.org/2000/svg" width="11.8" height="13.8" viewBox="0 0 8.89 10.16"><path id="Path_303" data-name="Path 303" d="M64.44 135.24a1.27 1.27 0 0 0 1.28 1.27h5.08a1.27 1.27 0 0 0 1.27-1.27v-6.35h-7.63Z" transform="translate(-63.8 -126.35)"/><path id="Path_304" data-name="Path 304" d="M37.94.64V0H35.4v.64h-3.18V1.9h8.9V.64Z" transform="translate(-32.22)"/></svg>';
							$output .='</a>';
						}
						$output .='<input type="hidden" class="nxt-wp-login-logo-img-val" name="nxt-wp-login-logo-img-val" id="nxt-img-id" value="">';
					$output .='</div>';
				$output .='</div>';
			}
			return $output;
		}

		/** Background Field */
		public static function background_field( $args= [], $value = [] ){
			$output = ''; $new_val = [];
			if( !empty($args) && isset($args['name']) ){
				if(!empty($value) && !empty($value[$args['name']])){
					$new_val = $value[$args['name']];
				}else{
					$new_val = [
						'open' => 0,
						'bgType' => '',
						'background_color' => '',
						'background_gradient' => ['start_color' => '', 'end_color' => '', 'type' => 'linear', 'angle' => ''],
						'background_image' => ['id' => '', 'url' => '', 'title' => '']
					];
				}

				$resetAct = (!empty($new_val) && !empty($new_val['open'])) ? 'active' : '';

				$data_attr = 'data-attr="'.htmlspecialchars(json_encode($new_val, true), ENT_QUOTES, 'UTF-8').'"';
				$output .='<div class="nxt-field nxt-field-background nxt-field-color-advanced"  data-name="'.esc_attr($args['name']).'" '.$data_attr.'>';
					if( !empty($args['label']) ){
						$output .= '<label>'.wp_kses_post($args['label']).'</label>';
					}
					$output .='<div class="nxt-flex nxt-reset-btn">';
						$output .='<span class="nxt-open-clear '.esc_attr($resetAct).'" role="button" onclick="resetVal(this,`'.esc_attr($args['name']).'`)">';
							$output .='<svg xmlns="http://www.w3.org/2000/svg" width="11.13" height="13.98" viewBox="0 0 14.13 16.98"><path d="M6.41 0v1.47A7.05 7.05 0 0 0 2 13.41l1.78-1.59A4.68 4.68 0 0 1 6.4 3.86v1.37L10.6 2.6Zm5.72 3.57-1.76 1.6a4.68 4.68 0 0 1-2.66 7.94v-1.35l-4.18 2.61 4.18 2.61v-1.47a7.05 7.05 0 0 0 4.42-11.94Z" fill="#888"/></svg>';
						$output .='</span>';
					$output .='</div>';
					$output .='<div class="nxt-field-button-list nxt-ml-auto">';
						$output .='<button class="nxt-button bg-color" onclick="nxtBackground(this,`'.esc_attr($args['name']).'`)" >';
							$output .='<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 13.33h6M11 2.33a1.41 1.41 0 1 1 2 2l-8.33 8.34-2.67.66.67-2.66L11 2.33Z" stroke="#727272" stroke-width="1.33" stroke-linecap="round" stroke-linejoin="round"/></svg>';
						$output .='</button>';
						$output .='<button class="nxt-button bg-gradient" onclick="nxtBackground(this,`'.esc_attr($args['name']).'`)" >';
							$output .='<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M14 3v9.35L2.78 3H14ZM1 3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V3Z" fill="#727272"/></svg>';
						$output .='</button>';
						$output .='<button class="nxt-button bg-image" onclick="nxtBackground(this,`'.esc_attr($args['name']).'`)" >';
							$output .='<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M12.67 2H3.33C2.6 2 2 2.6 2 3.33v9.34C2 13.4 2.6 14 3.33 14h9.34c.73 0 1.33-.6 1.33-1.33V3.33C14 2.6 13.4 2 12.67 2Z" stroke="#727272" stroke-width="1.33" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.67 6.67a1 1 0 1 0 0-2 1 1 0 0 0 0 2ZM14 10l-3.33-3.33L3.33 14" stroke="#727272" stroke-width="1.33" stroke-linecap="round" stroke-linejoin="round"/></svg>';
						$output .='</button>';
					$output .='</div>';

					$output .='<div class="nxt-d-flex nxt-bg-color nxt-mt-10">';
						$output .=self::color_field(['label' => 'Background Color', 'name'=> 'background_color', 'gradComp' => '', 'mainComp'=>$args['name']], $new_val );
					$output .='</div>';
					$output .='<div class="nxt-d-flex nxt-bg-gradient nxt-mt-10">';
						$output .=self::gradient_field(['label' => 'Gradient Background', 'mainComp'=>$args['name'], 'name'=> 'background_gradient'], $new_val );
					$output .='</div>';
					$output .='<div class="nxt-d-flex nxt-bg-image nxt-mt-10">';
						$output .=self::image_field(['label' => 'Background Image', 'name'=> 'background_image', 'mainComp'=>$args['name']], $new_val );
						$output .= self::select_field(['label' => 'Position', 'mainComp'=> $args['name'], 'imgComp'=> 'background_image', 'name'=> 'position', 'options' => ['default'=> 'Default','left top'=> 'Left Top','left center'=> 'Left Center','left bottom'=> 'Left Bottom','center top'=> 'Center Top','center center'=> 'Center Center','center bottom'=> 'Center Bottom','right top'=> 'Right Top','right center'=> 'Right Center','right bottom'=> 'Right Bottom']], $new_val['background_image'] );
						$output .= self::select_field(['label' => 'Attachment', 'mainComp'=> $args['name'], 'imgComp'=> 'background_image', 'name'=> 'attachment', 'options' => ['default'=> 'Default','scroll'=> 'Scroll','fixed'=> 'Fixed']], $new_val['background_image'] );
						$output .= self::select_field(['label' => 'Repeat', 'mainComp'=> $args['name'], 'imgComp'=> 'background_image', 'name'=> 'repeat', 'options' => ['default'=> 'Default','no-repeat'=> 'No Repeat','repeat'=> 'Repeat','repeat-x'=> 'Repeat X','repeat-y'=> 'Repeat Y']], $new_val['background_image'] );
						$output .= self::select_field(['label' => 'Size', 'mainComp'=> $args['name'], 'imgComp'=> 'background_image', 'name'=> 'size', 'options' => ['default'=> 'Default','auto'=> 'Auto','cover'=> 'Cover','contain'=> 'Contain']], $new_val['background_image'] );
					$output .='</div>';
				$output .='</div>';
				// nxt-mb-15 nxt-d-flex nxt-align-center nxt-bg-field
			}
			return $output;
		}

		/** Tabs Field */
		public static function tabs_field($args= []){
			$output = '';
			if(!empty($args)){
				$output .='<div class="nxt-tabs-panel">';
				foreach($args as $index => $name) :
					$active = ($index==0) ? 'active-tab' : '';
					$output .='<button class="nxt-tab-menu '.esc_attr($active).'" data-id="'.esc_attr($name).'" onclick="nxtTabs(this)">'.esc_html($name).'</button>';
				endforeach;
				$output .='</div>';
			}
			return $output;
		}


		/** Below Functions For CSS Generator */

		/** Typography Start */
		public static function nxtTypoCss($val, $selector, $device){
			$data = [ 'md' => [], 'sm' => [], 'xs' => [] ];
			if(!empty($val) && !empty($val['open'])){
				$typocss = '';
				if(!empty($val['font_family']) && !empty($val['font_family']['family']) && !empty($val['font_family']['type']) ){
					$typocss .= 'font-family: "'.$val['font_family']['family'].'",'.$val['font_family']['type'].';';
				}
				if(!empty($val['font_weight'])){
					$typocss .= 'font-weight: '.$val['font_weight'].';';
				}
				if(!empty($val['font_style'])){
					$typocss .= 'font-style: '.$val['font_style'].';';
				}
				if(!empty($val['text_transform'])){
					$typocss .= 'text-transform: '.$val['text_transform'].';';
				}
				if(!empty($val['text_decoration'])){
					$typocss .= 'text-decoration: '.$val['text_decoration'].';';
				}

				if(!empty($typocss)){
					$css = $selector.'{'.$typocss.'}';
					array_push( $device['md'], $css);
				}

				if (isset($val['font_size']) && $val['font_size']!='') {
					$data = self::_push( self::_device( $val['font_size'], 'font-size:{{key}}'), $data);
				}
				if (isset($val['line_height']) && $val['line_height']!='') {
					$data = self::_push( self::_device( $val['line_height'], 'line-height:{{key}}'), $data);
				}
				if (isset($val['letter_spacing']) && $val['letter_spacing']!='') {
					$data = self::_push( self::_device( $val['letter_spacing'], 'letter-spacing:{{key}}'), $data);
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
			if(!empty($val) && !empty($val['open'])){
				$bdrcss = '';
				if(!empty($val['border_type'])){
					$bdrcss .= 'border-style: '.$val['border_type'].';';
				}
				if(!empty($val['border_color'])){
					$bdrcss .= 'border-color: '.$val['border_color'].';';
				}

				if(!empty($bdrcss)){
					$css = $selector.'{'.$bdrcss.'}';
					array_push( $device['md'], $css);
				}

				if (gettype($val['border_width']) === 'array') {
					$data = self::_push(self::_customDevice($val['border_width'], 'border-width:{{key}};'), $data);

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
				if(!empty($val['shadowtype'])){
					if($val['shadowtype']=='text'){
						$shadowCss = 'text-shodow:' . $val['x'].'px '.$val['y'].'px '.$val['blur'].'px '.$val['color'].';';
					}else if($val['shadowtype']=='drop'){
						$shadowCss = 'filter: drop-shadow('.$val['x'].'px '.$val['y'].'px '.$val['blur'].'px '.$val['color'].');';
					}else{
						$shadowCss = 'box-shadow:'.((!empty($val['type']) && $val['type']=='inset') ? $val['type'] : '').' '.$val['x'].'px '.$val['y'].'px '.$val['blur'].'px '.$val['spread'].'px '.$val['color'].';';
					}
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
			if(!empty($val) && !empty($selector) && !empty($val['open'])){
				if($val['bgType']=='color'){
					return self::nxtColorCss($val['background_color'], $selector, 'background-color', $device);
				}else if($val['bgType']=='gradient'){
					return self::nxtGradientCss($val['background_gradient'], $selector, $device);
				}else if($val['bgType']=='image'){
					$imgVal = $val['background_image'];
					if(!empty($imgVal['url'])){
						$css = $selector.'{';
							$css .= 'background-image: url('.$imgVal['url'].');';
						if(!empty($imgVal['position']) && $imgVal['position']!='default'){
							$css .= 'background-position: '.$imgVal['position'].';';
						}
						if(!empty($imgVal['repeat']) && $imgVal['repeat']!='default'){
							$css .= 'background-repeat: '.$imgVal['repeat'].';';
						}
						if(!empty($imgVal['size']) && $imgVal['size']!='default'){
							$css .= 'background-size: '.$imgVal['size'].';';
						}
						if(!empty($imgVal['attachment']) && $imgVal['attachment']!='default'){
							$css .= 'background-attachment: '.$imgVal['attachment'].';';
						}
						$css .= '}';
						array_push( $device['md'], $css);
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