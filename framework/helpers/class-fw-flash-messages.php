<?php if (!defined('FW')) die('Forbidden');

/**
 * Set flash messages
 **
 * Store messages in session (to not be lost between redirects) and remove them after they were shown to the user
 */
class FW_Flash_Messages
{
	private static $available_types = array(
		// 'type' => 'backend class/type' (only 2 backend types exists: error and updated)
		'error'   => 'error',
		'warning' => 'update-nag',
		'info'    => 'updated',
		'success' => 'updated'
	);

	private static $session_key = 'fw_flash_messages';

	private static $frontend_printed = false;

	private static function get_messages()
	{
		$messages = FW_Session::get(self::$session_key);

		if (!is_array($messages)) {
			$messages = array_fill_keys(array_keys(self::$available_types), array());
		}

		return $messages;
	}

	private static function set_messages(array $messages)
	{
		FW_Session::set(self::$session_key, $messages);
	}

	/**
	 * Remove messages with ids from pending remove
	 */
	private static function process_pending_remove_ids()
	{
		$pending_remove = array();

		foreach (self::get_messages() as $messages) {
			if (empty($messages)) {
				continue;
			}

			foreach ($messages as $message) {
				if (empty($message['remove_ids'])) {
					continue;
				}

				foreach ($message['remove_ids'] as $remove_id) {
					$pending_remove[$remove_id] = true;
				}
			}
		}

		if (empty($pending_remove)) {
			return;
		}

		$types = self::get_messages();

		foreach ($types as $type => $messages) {
			if (empty($messages)) {
				continue;
			}

			foreach ($messages as $id => $message) {
				if (isset($pending_remove[$id])) {
					unset($types[$type][$id]);
				}
			}
		}

		self::set_messages( $types );
	}

	/**
	 * Add flash message
	 **
	 * @param string $id          Unique id of the message
	 * @param string $message     Message (can be html)
	 * @param string $type        Type from $available_types
	 * @param array  $removed_ids Remove flashes with this id(s)
	 *                            (For e.g. your message is success and some known error messages ids needs to be removed
	 *                            because they are not relevant anymore, your success message suppress/cancels them)
	 */
	public static function add($id, $message, $type = 'info', array $removed_ids = array())
	{
		if (!isset(self::$available_types[$type])) {
			trigger_error(sprintf(__('Invalid flash message type: %s', 'tfuse'), $type), E_USER_WARNING);
			$type = 'info';
		}

		$messages = self::get_messages();

		$messages[$type][$id] = array(
			'message'    => $message,
			'remove_ids' => $removed_ids,
		);

		self::set_messages($messages);
	}

	/**
	 * Use this method to print messages html in backend
	 * (used in action at the end of the file)
	 * @internal
	 */
	public static function _print_backend()
	{
		self::process_pending_remove_ids();

		$html = array_fill_keys(array_keys(self::$available_types), '');

		$all_messages = self::get_messages();

		foreach ($all_messages as $type => $messages) {
			if (!empty($messages)) {
				foreach ($messages as $id => $data) {
					$html[$type] .= '<div class="'. self::$available_types[$type] .' fw-flash-message"><p>'. $data['message'] .'</p></div>';

					unset($all_messages[$type][$id]);
				}

				$html[$type] = '<div class="fw-flash-type-'. $type .'">'. $html[$type] .'</div>';
			}
		}

		unset($success, $error, $info);

		self::set_messages($all_messages);

		echo '<div class="fw-flash-messages">'. implode("\n\n", $html) .'</div>';
	}

	/**
	 * Use this method to print messages html in frontend
	 * @return bool If some html was printed or not
	 */
	public static function _print_frontend()
	{
		self::process_pending_remove_ids();

		$html = array_fill_keys(array_keys(self::$available_types), '');
		$all_messages = self::get_messages();

		$messages_exists = false;

		foreach ($all_messages as $type => $messages) {
			if (empty($messages)) {
				continue;
			}

			foreach ($messages as $id => $data) {
				$html[$type] .= '<li class="fw-flash-message">'. nl2br($data['message']) .'</li>';

				unset($all_messages[$type][$id]);
			}

			$html[$type] = '<ul class="fw-flash-type-'. $type .'">'. $html[$type] .'</ul>';

			$messages_exists = true;
		}

		self::set_messages($all_messages);

		self::$frontend_printed = true;

		if ($messages_exists) {
			echo '<div class="fw-flash-messages">';
			echo implode("\n\n", $html);
			echo '</div>';

			return true;
		} else {
			return false;
		}
	}

	public static function _frontend_printed()
	{
		return self::$frontend_printed;
	}
}

if (is_admin()) {
	/**
	 * Start the session before the content is sent to prevent the "headers already sent" warning
	 * @internal
	 */
	function _action_fw_flash_message_backend_prepare() {
		if (!session_id()) {
			session_start();
		}
	}
	add_action('current_screen', '_action_fw_flash_message_backend_prepare', 9999);

	/**
	 * Display flash messages in backend as notices
	 */
	add_action( 'admin_notices', array( 'FW_Flash_Messages', '_print_backend' ) );
} else {
	/**
	 * Start the session before the content is sent to prevent the "headers already sent" warning
	 * @internal
	 */
	function _action_fw_flash_message_frontend_prepare() {
		if (!session_id()) {
			session_start();
		}
	}
	add_action('send_headers', '_action_fw_flash_message_frontend_prepare', 9999);

	/**
	 * Print flash messages in frontend if this has not been done from theme
	 */
	function _action_fw_flash_message_frontend_print() {
		if (FW_Flash_Messages::_frontend_printed()) {
			return;
		}

		if (!FW_Flash_Messages::_print_frontend()) {
			return;
		}

		echo
		'<script type="text/javascript">'.
		'  (function(){'.
		'    if (typeof jQuery === "undefined") return;'.
		'    jQuery(function($){'.
		'      var $container = $("#content .entry-content:first");'.
		'      if (!$container.length) $container = $(document.body);'.
		'      $(".fw-flash-messages").prependTo($container);'.
		'    });'.
		'  })();'.
		'</script>'.
		'<style type="text/css">'.
		'  .fw-flash-messages .fw-flash-type-error { color: #f00; }'.
		'  .fw-flash-messages .fw-flash-type-warning { color: #f70; }'.
		'  .fw-flash-messages .fw-flash-type-success { color: #070; }'.
		'  .fw-flash-messages .fw-flash-type-info { color: #07f; }'.
		'</style>';
	}
	add_action('wp_footer', '_action_fw_flash_message_frontend_print', 9999);
}
