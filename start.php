<?php
/**
 * Elgg videos plugin
 *	Author : Sarath C | Team Webgalli
 *	Team Webgalli | Elgg developers and consultants
 *	Mail : info [at] webgalli [dot] com
 *	Web	: http://webgalli.com
 *	Skype : 'team.webgalli'
 *	@package Elgg-videos
 * 	Plugin info : Upload/ Embed videos. Save uploaded videos in youtube and save your bandwidth and server space
 *	Licence : GNU2
 *	Copyright : Team Webgalli 2011-2015
 */

elgg_register_event_handler('init', 'system', 'videos_init');
/**
 * video init
 */
function videos_init() {
	$root = dirname(__FILE__);
	elgg_register_library('elgg:videos', elgg_get_plugins_path() . 'videos/lib/videos.php');
	// For now we can use the embed video library of Cash. Once that plugin is updated to 1.8
	// we can use the elgg's manifest file to make the users use that library along with this
	// and remove the library from this plugin
	// V1.4 Added support for https in Cash's library
	elgg_register_library('elgg:videos:embed', elgg_get_plugins_path() . 'videos/lib/embed_video.php');
	elgg_register_library('elgg:youtube_api', elgg_get_plugins_path() . 'videos/lib/youtube_functions.php');
	$action_path = "$root/actions/videos";
	elgg_register_action('videos/save', "$action_path/save.php");
	elgg_register_action('videos/delete', "$action_path/delete.php");
	elgg_register_menu_item('site', array(
		'name' => 'videos',
		'text' => elgg_echo('videos'),
		'href' => 'videos/all'
	));
	elgg_register_plugin_hook_handler('register', 'menu:owner_block', 'videos_owner_block_menu');
	elgg_register_page_handler('videos', 'videos_page_handler');
	elgg_extend_view('css/elgg', 'videos/css');
	elgg_register_widget_type('videos', elgg_echo('videos'), elgg_echo('videos:widget:description'));

	elgg_register_notification_event('object', 'videos', array('create'));
	elgg_register_plugin_hook_handler('prepare', 'notification:create:object:videos', 'videos_prepare_notification');

	elgg_register_plugin_hook_handler('entity:url', 'object', 'videos_set_url');

	elgg_register_entity_type('object', 'videos');
	add_group_tool_option('videos', elgg_echo('videos:enablevideos'), true);
	elgg_extend_view('groups/tool_latest', 'videos/group_module');
	$views = array('output/longtext','output/plaintext');
	foreach($views as $view){
		elgg_register_plugin_hook_handler("view", $view, "videos_view_filter", 500);
	}	
}
/**
 * Process the Elgg views for a matching video URL
*/
function videos_view_filter($hook, $entity_type, $returnvalue, $params){
	elgg_load_library('elgg:videos:embed');
	$patterns = array(	'#(((https?://)?)|(^./))(((www.)?)|(^./))youtube\.com/watch[?]v=([^\[\]()<.,\s\n\t\r]+)#i',
						'#(((https?://)?)|(^./))(((www.)?)|(^./))youtu\.be/([^\[\]()<.,\s\n\t\r]+)#i',
						'/(https?:\/\/)(www\.)?(vimeo\.com\/groups)(.*)(\/videos\/)([0-9]*)/',
						'/(https?:\/\/)(www\.)?(vimeo.com\/)([0-9]*)/',
						'/(https?:\/\/)(www\.)?(metacafe\.com\/watch\/)([0-9a-zA-Z_-]*)(\/[0-9a-zA-Z_-]*)(\/)/',
						'/(https?:\/\/www\.dailymotion\.com\/.*\/)([0-9a-z]*)/',
						);
	$regex = "/<a[\s]+[^>]*?href[\s]?=[\s\"\']+"."(.*?)[\"\']+.*?>"."([^<]+|.*?)?<\/a>/";
	if(preg_match_all($regex, $returnvalue, $matches, PREG_SET_ORDER)){
 		foreach($matches as $match){
			foreach ($patterns as $pattern){
				if (preg_match($pattern, $match[2]) > 0){
					$returnvalue = str_replace($match[0], videoembed_create_embed_object($match[2], uniqid('videos_embed_'), 350), $returnvalue);
				}				
			}
		}
	}
	return $returnvalue;
}	
/**
 * Dispatcher for videos.
 * URLs take the form of
 *  All videos:        videos/all
 *  User's videos:     videos/owner/<username>
 *  Friends' videos:   videos/friends/<username>
 *  View video:        videos/view/<guid>/<title>
 *  New video:         videos/add/<guid> (container: user, group, parent)
 *  Edit video:        videos/edit/<guid>
 *  Group videos:      videos/group/<guid>/owner
 * Title is ignored
 * @param array $page
 */
function videos_page_handler($page) {
	elgg_load_library('elgg:videos');
	elgg_push_breadcrumb(elgg_echo('videos'), 'videos/all');
	elgg_push_context('videos');
	$pages = dirname(__FILE__) . '/pages/videos';
	switch ($page[0]) {
		case "all":
			include "$pages/all.php";
			break;
		case "owner":
			include "$pages/owner.php";
			break;
		case "friends":
			include "$pages/friends.php";
			break;
		case "read":
		case "view":
			set_input('guid', $page[1]);
			include "$pages/view.php";
			break;
		case "add":
			gatekeeper();
			include "$pages/add.php";
			break;
		case "edit":
			gatekeeper();
			set_input('guid', $page[1]);
			include "$pages/edit.php";
			break;
		case 'group':
			group_gatekeeper();
			include "$pages/owner.php";
			break;
		default:
			return false;
	}
	elgg_pop_context();
	return true;
}
/**
 * Populates the ->getUrl() method for videoed objects
 * @param ElggEntity $entity The videoed object
 * @return string videoed item URL
 */
function videos_set_url($hook, $type, $url, $params) {
	$entity = $params['entity'];
	if (elgg_instanceof($entity, 'object', 'videos')) {
		return "videos/view/" . $entity->getGUID() . "/" . elgg_get_friendly_title($entity->title);
	}
}
/**
 * Add a menu item to an ownerblock
 * 
 * @param string $hook
 * @param string $type
 * @param array  $return
 * @param array  $params
 */
function videos_owner_block_menu($hook, $type, $return, $params) {
	if (elgg_instanceof($params['entity'], 'user')) {
		$url = "videos/owner/{$params['entity']->username}";
		$item = new ElggMenuItem('videos', elgg_echo('videos'), $url);
		$return[] = $item;
	} else {
		if ($params['entity']->videos_enable != 'no') {
			$url = "videos/group/{$params['entity']->guid}/owner";
			$item = new ElggMenuItem('videos', elgg_echo('videos:group'), $url);
			$return[] = $item;
		}
	}
	return $return;
}

/**
 * Returns the body of a notification message
 * 
 * @param string                          $hook         Hook name
 * @param string                          $type         Hook type
 * @param Elgg_Notifications_Notification $notification The notification to prepare
 * @param array                           $params       Hook parameters
 * @return Elgg_Notifications_Notification
 */
function videos_prepare_notification($hook, $type, $notification, $params) {
	$entity = $params['event']->getObject();
	$owner = $params['event']->getActor();
	$recipient = $params['recipient'];
	$language = $params['language'];
	$method = $params['method'];

	$descr = $entity->description;
	$title = $entity->title;

	$notification->subject = elgg_echo('videos:notify:subject', array($title), $language); 
	$notification->body = elgg_echo('videos:notify:body', array(
		$owner->name,
		$title,
		$descr,
		$entity->getURL()
	), $language);
	$notification->summary = elgg_echo('videos:notify:summary', array($entity->title), $language);

	return $notification;
} 
