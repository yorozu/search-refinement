<?php
/*
*/


class Form_Tags{

	/* -------------------------------------------------------------------------- */
	function textinput($text, $var, $pbOptions, $prefix) {
		echo '<tr><th scope="row" valign="top"><label for ="' . $var . '">' . __($text) . '</label></th>';
		//echo  '<td><input type="text" name="' . $prefix.'['.$var . ']" value="' . $pbOptions[$var] . '"></td></tr>';
		echo '<td>' . $this->textinputbox($var, $pbOptions, $prefix) . '</td></tr>';
	}
	function textinputbox($var, $pbOptions, $prefix) {
		$output  = '<input type="text" name="' . $prefix.'['.$var . ']" value="';
		if ( isset($pbOptions[$var]) ){
			$output .= (is_array($pbOptions[$var])) ? join(',',$pbOptions[$var]) : $pbOptions[$var];
		}
		$output .= '">';
		return $output;
	}
	function checkbox($text,$var,$pbOptions, $prefix, $onClick = '') {
		return '<label id="lbl_' . $var . '"><input type="checkbox" id="cb_' . $var . '" name="' . $prefix.'['.$var.']"' .
		($onClick != '' ? ' onClick="' . $onClick .'" ' : '') .
		($pbOptions [$var] ? 'checked="checked"' : '') . '>&nbsp;' . __($text) . "</label><br/>\n";
	}
	function radiobutton($name, $value, $text, $pbOptions, $prefix, $onClick = '') {
		return '<label><input type="radio" name="'. $prefix .'['.$name.']" value="' . $value . '"' .
		($onClick != '' ? ' onClick="' . $onClick .'" ' : '') .
		 ($pbOptions[$name] == $value ? " checked " : '') .  '>&nbsp;' . __($text) . "</label>\n";
	}
	function selectoption($value, $text='', $selected='') {
		if ( empty($text) ) { $text = $value; }
		$html[] = '<option value="' . esc_attr($value) . '"';
		$html[] .= ($selected == true ? ' selected="selected" ' : '') .  '>' . __($text) . "</option>\n";
		return join(' ', $html);
	}
	function submitbutton() {
		echo '<input type="hidden" name="action" value="update" />';
        echo wp_nonce_field($this->prefix);
		echo '<p class="submit">';
		echo '<input type="submit" class="button-primary" value="' . __('Save Changes') . '" />';
		echo '</p>';
	}
	function resetbutton() {
		echo '<input type="hidden" name="action" value="reset" />';
        echo wp_nonce_field($this->prefix);
		echo '<p class="submit">';
		echo '<input type="submit" class="button-primary" value="' . __('Reset') . '" />';
		echo '</p>';
	}
	/* -------------------------------------------------------------------------- */
}
?>