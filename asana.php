<?php

/**
 * This is based on the implementation here:
 * https://gist.github.com/AWeg/5814427
 */

function asanaRequest($methodPath, $httpMethod = 'GET', $body = null, $cached = true, $wait = true)
{
	global $authToken;
	global $DEBUG;
	global $APPENGINE;
	global $ratelimit;

	$key = false;
	$ratelimit = getRateLimit();

	if ($APPENGINE && strcmp($httpMethod,'GET') == 0 && $cached) {
		$key = sha1($authToken['refresh_token']) . ":" . $methodPath;

		$data = getCached($key);

		if ($DEBUG >= 2) {
			pre(array('request' => $body, 'response' => $data), "Memcache: " . $methodPath);
		}

		if ($data != false) {
			return $data;
		}
	}

	$access_token = $authToken['access_token'];
	// Check for expiry
	$refresh = time() + 600;
	if (!$access_token || $authToken['expires'] < $refresh) {
		// Look for an already-refreshed token
		$tokenKey = sha1($authToken['refresh_token']) . ":access_token";
		$refreshed_token = getCached($tokenKey);
		
		if ($refreshed_token && $refreshed_token['expires'] > $refresh) {
			$access_token = $refreshed_token['access_token'];
			$authToken['access_token'] = $refreshed_token['access_token'];
			$authToken['expires'] = $refreshed_token['expires'];
		} else {
			// Refresh the token
			global $asana_app;
			$asana_config = $asana_app[$authToken['host']];
			$client = Asana\Client::oauth(array(
			    'client_id' => $asana_config['key'],
			    'client_secret' => $asana_config['secret'],
			    'redirect_uri' => $asana_config['redirect'],
			    'refresh_token' => $authToken['refresh_token']
			));
			$access_token = $client->dispatcher->refreshAccessToken();
			$authToken['access_token'] = $access_token;
			$authToken['expires'] = time() + $client->dispatcher->expiresIn;

			cache($tokenKey, $authToken);
		}
	}

	$url = "https://app.asana.com/api/1.0/$methodPath";
	$headers = array(
		"Content-type: application/json",
		"Authorization: Bearer $access_token"
	);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // SSL cert of Asana is selfmade
    curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);

	$jbody = $body;
	if ($jbody)
	{
		if (!is_string($jbody))
		{
			$jbody = json_encode($body);
		}
		curl_setopt($ch, CURLOPT_POSTFIELDS, $jbody);
	}

	for ($i = 0; $i < 10; $i++) {
		if ($ratelimit > time()) {
			if ($wait) {
				echo("Waiting for rate limit (". ($ratelimit - time()) . "s)");
				time_sleep_until($ratelimit);
			} else {
				die ("Rate limit reached: retry in " . ($ratelimit - time()) . "s");
			}
		}

		$data = curl_exec($ch);
		$error = curl_error($ch);
		$result = null;

		// See http://stackoverflow.com/a/27909889/37416
		if (version_compare(PHP_VERSION, '5.4.0', '>=') && !(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
		    /** In PHP >=5.4.0, json_decode() accepts an options parameter, that allows you
		     * to specify that large ints (like Steam Transaction IDs) should be treated as
		     * strings, rather than the PHP default behaviour of converting them to floats.
		     */
		    $result = json_decode($data, true, 512, JSON_BIGINT_AS_STRING);
		} else {
		    /** Not all servers will support that, however, so for older versions we must
		     * manually detect large ints in the JSON string and quote them (thus converting
		     *them to strings) before decoding, hence the preg_replace() call.
		     */
		    $max_int_length = strlen((string) PHP_INT_MAX) - 1;
		    $json_without_bigints = preg_replace('/:\s*(-?\d{'.$max_int_length.',})/', ': "$1"', $data);
		    $result = json_decode($json_without_bigints, true);
		}
		// $result = json_decode($data, true);

		if(isset($result['retry_after'])) {
			$ratelimit = time() + $result['retry_after'];
			setRateLimit($ratelimit);

			if ($wait)
				continue;
		}
		break;
	}

	curl_close($ch);

	cache($key, $result);

	if ($DEBUG >= 2) {
		pre(array('request' => $body, 'response' => $result), "$httpMethod " . $url);
	}
	return $result;
}

function cache($key, $result) {
	global $APPENGINE;
	if ($APPENGINE && $key) {
		try {
			getMemcache()->set($key, $result, false, 120);
		} catch (Exception $e) {
			
		}
	}
}

function getCached($key) {
	global $APPENGINE;
	if ($APPENGINE) {
		try {
			$data = getMemcache()->get($key);
			return $data;
		} catch (Exception $e) {
			
		}
	}
	return null;
}

function getMemcache() {
	global $memcache;
	if (!$memcache)
		$memcache = new Memcache;
	return $memcache;
}

function isCancelled($channel) {
	global $authToken;
	$key = sha1($authToken['refresh_token']) . ":$channel:cancelled";
	$cancelled = getMemcache()->get($key);
	return $cancelled;
}

function cancel($channel) {
	global $authToken;
	$key = sha1($authToken['refresh_token']) . ":$channel:cancelled";
	$cancelled = getMemcache()->set($key, true);
	return $cancelled;
}

function getPendingRequests() {
	global $authToken;
	$key = sha1($authToken['refresh_token']) . ":issuedRequests:" . floor(time()/60);
	$pending = getMemcache()->get($key);
	return $pending;
}

function incrementRequests($value = 1) {
	global $authToken;
	$key = sha1($authToken['refresh_token']) . ":issuedRequests:" . floor(time()/60);
	$pending = getMemcache()->increment($key, $value);
	if (!$pending) {
		getMemcache()->set($key, $value, false, 120);
		return $value;
	}
	return $pending;
}

function getRateLimit() {
	global $APPENGINE;
	global $authToken;
	$ratelimit = false;
	if ($APPENGINE) {
		$key = sha1($authToken['refresh_token']) . ":ratelimit";
		$ratelimit = getMemcache()->get($key);
	}
	return $ratelimit;
}

function setRateLimit($ratelimit) {
	global $APPENGINE;
	global $authToken;
	if ($APPENGINE) {
		$key = sha1($authToken['refresh_token']) . ":ratelimit";
		getMemcache()->set($key, $ratelimit);
	}
	return $ratelimit;
}

function notifyCreated($project) {
	global $pusher;
	global $channel;
	if ($pusher) {
		$pusher->trigger($channel, 'created', $project);
	}
}

function progress($text) {
	global $pusher;
	global $channel;
	if ($pusher) {
		$body = array('message' => $text);
		$pusher->trigger($channel, 'progress', $body);
	} else {
		print "<p>" . $text . "</p>\n";
		flush();
	}
}

function error($body, $title, $style) {
	global $pusher;
	global $channel;
	if ($pusher) {
		$error = array ('error' => $title, 'api_response' => $body);
		$pusher->trigger($channel, 'error', $error);
		if (strcmp($style, 'danger') == 0)
			throw new Exception(json_encode($error, JSON_PRETTY_PRINT));
	} else {
		print '<div class="bs-callout bs-callout-' . $style . '">';
		if ($title)
			print "<h4>$title</h4>";
		print "<pre>";
		print(json_encode($body, JSON_PRETTY_PRINT));
		print "</pre></div>\n";
		flush();
	}
}

function p($text) {
	progress($text);
}

function pre($o, $title = false, $style = 'info') {
	error($o, $title, $style);
}

function isError($result) {
	return isset($result['errors']) || !isset($result['data']);
}

function isOrganisation($workspace) {
	$org = isset($workspace['is_organization']) && $workspace['is_organization'];
	if ($workspace['name'] == 'Personal Projects')
		$org = false;
		
	return $org;
}

// function createTag($tag, $workspaceId, $newTaskId) {
//  	p("Creating tag: " . $tag->name);
//
//  	// Create new tag
//     $data = array('data' => $tag);
//     $result = asanaRequest("workspaces/$workspaceId/tags", "POST", $data);
//
//  	// Assign tag to task
//     $data = array("data" => array("tag" => $result["data"]["id"]));
//     $result = asanaRequest("tasks/$newTaskId/addTag", "POST", $data);
//
// }

function cleanTask($task) {
	global $DEBUG;
	if ($DEBUG) pre($task, "Cleaning task");
	// "message": ".assignee_status: Schedule status shouldn't be set for unassigned tasks"
	if (!isset($task['assignee'])) {
		unset($task['assignee_status']);
		if ($DEBUG) pre($task, "Removed Assignee Status ('Schedule status shouldn't be set for unassigned tasks')", 'warn');
	}
	if (isset($task['due_at'])) {
		unset($task['due_on']);
		if ($DEBUG) pre($task, "Removed double due time ('You may only provide one of due_on or due_at!')", 'warn');
	}
	
	return $task;	
}

function createTask($workspaceId, $projectId, $task)
{
	// Set projects
	$task['projects'] = array($projectId);

	// Validate task data
	$task = cleanTask($task);
	
	// Create new task
	$data = array('data' => $task);
	$result = asanaRequest("workspaces/$workspaceId/tasks", 'POST', $data);

	// Try to remove assignee if an error is returned
	// TODO check assignee exists before submitting the request
	if (isError($result) && isset($task['assignee'])) {
		unset($task['assignee']);
		
		// Validate task data
		$task = cleanTask($task);
	
		$data = array('data' => $task);
		$result = asanaRequest("workspaces/$workspaceId/tasks", 'POST', $data);
	}

	// Check result
	if (!isError($result))
	{
		// Display result
		global $DEBUG;
		if ($DEBUG) pre($result);

		$newTask = $result['data'];
		return $newTask;
	}
	else {
		pre($result, "Error creating task", 'danger');
	}

	return $result;
}

function createProject($workspaceId, $name, $teamId, $notes)
{
	p("Creating project: " . $name);
	$data = array('data' => array('name' => $name));
	if ($workspaceId)
		$data['data']['workspace'] = $workspaceId;
	if ($notes)
		$data['data']['notes'] = $notes;
	if ($teamId)
		$data['data']['team'] = $teamId;

	$result = asanaRequest("projects", 'POST', $data);

	if (!isError($result))
	{
		$newProject = $result['data'];
		return $newProject;
	}
	else {
		pre($result, "Error creating project!", 'danger');
	}

	return $result;
}

function copySubtasks($taskId, $newTaskId, $depth, $workspaceId) {
    $depth++;
    if ($depth > 10) {
        return FALSE;
    }

    // GET subtasks of task
    $result = asanaRequest("tasks/$taskId/subtasks");
    if (isError($result)) {
		pre($result, "Error getting subtasks", 'danger');
	}
    $subtasks = $result["data"];


    if ($subtasks){     // does subtask exist?
        for ($i= count($subtasks) - 1; $i >= 0; $i--) {

            $subtask = $subtasks[$i];

            $subtaskId = $subtask['id'];

		    // get data for subtask
		    // TODO external field
		    $result = asanaRequest("tasks/$subtaskId?opt_fields=assignee,assignee_status,completed,due_on,due_at,hearted,name,notes");
		    $task = $result['data'];
		    unset($task["id"]);
		    
			p(str_repeat("&nbsp;", $depth) . "Creating subtask: " . $task['name']);
		    
		    if (isset($task["assignee"]))
		        $task["assignee"] = $task["assignee"]["id"];

		    // create Subtask
		    $data = array('data' => cleanTask($task));
		    $result = asanaRequest("tasks/$newTaskId/subtasks", 'POST', $data);
		    
		    // Try to remove assignee if an error is returned
			// TODO check assignee exists before submitting the request
			if (isError($result) && isset($task['assignee'])) {
				unset($task['assignee']);
				$data = array('data' => cleanTask($task));
				$result = asanaRequest("tasks/$newTaskId/subtasks", 'POST', $data);
			}

			if (isError($result)) {
				pre($result, "Failed to create subtask!", 'danger');
				return;
			}

			$newsubtask = $result["data"];
			$newSubId = $newsubtask['id'];
            
            global $APPENGINE;
            if ($APPENGINE) {
            	queueSubtask($subtaskId, $newSubId, $workspaceId, $depth);
            }
            else {
            	copySubtask($subtaskId, $newSubId, $workspaceId, $depth);
            }

        }
    }
}

function queueSubtask($subtaskId, $newSubId, $workspaceId, $depth) {
	global $channel;
	global $authToken;
	global $DEBUG;

	// Start task
	require_once("google/appengine/api/taskqueue/PushTask.php");

	$params = [
		'channel' => $channel,
		'authToken' => $authToken,
		'copy' => 'subtask',
		'subtaskId' => $subtaskId,
		'newSubId' => $newSubId,
		'workspaceId' => $workspaceId,
		'depth' => $depth,
		'debug' => $DEBUG
	];
	$job = new \google\appengine\api\taskqueue\PushTask('/process/subtask', $params);
	$task_name = $job->add();
}

function copySubtask($subtaskId, $newSubId, $workspaceId, $depth) {

    // add History
    copyHistory($subtaskId, $newSubId);

    //copy tags
    copyTags($subtaskId, $newSubId, $workspaceId);

    // subtask of subtask?
    copySubtasks($subtaskId, $newSubId, $depth, $workspaceId);
}

function copyHistory($taskId, $newTaskId) {

	$result = asanaRequest("tasks/$taskId/stories");
	if (isError($result))
	{
        pre($result, "Failed to copy history from source task!", 'danger');
		return;
	}

	$comments = array();
	foreach ($result['data'] as $story){
		$date = date('l M d, Y h:i A', strtotime($story['created_at']));
		$comment = " ­\n" . $story['created_by']['name'] . ' on ' . $date . ":\n" . $story['text'];
		$comments[] = $comment;
	}
	$comment = implode("\n----------------------", $comments);
	$data = array('data' => array('text' => $comment));
	$result = asanaRequest("tasks/$newTaskId/stories", 'POST', $data);

}

function copyTags ($taskId, $newTaskId, $newworkspaceId) {

    // GET Tags
    $result = asanaRequest("tasks/$taskId/tags");

    if (!isError($result))
    { 	// are there any tags?
        $tags = $result["data"];
		$result = asanaRequest("workspaces/$newworkspaceId/tags");
		if (isError($result))
		{
	        pre($result, "Failed to list tags in target workspace!", 'danger');
			return;
		}
		$existingtags = $result["data"];
		
        for ($i = count ($tags) - 1; $i >= 0; $i--) {

            $tag = $tags[$i];
            $tagName = $tag["name"];

            // does tag exist?
            $tagkey = "$newworkspaceId:tag:$tagName";
            $existingtag = getCached($tagkey);
            $tagisset = $existingtag != null;
            if (!$tagisset) {
	            for($j = count($existingtags) - 1; $j >= 0; $j--) {
	                $existingtag = $existingtags[$j];

	                if (strcmp($tagName,$existingtag["name"]) == 0) {
	                    $tagisset = true;
	                    $tagId = $existingtag["id"];
	                    break;
	                }
	            }
	        } else {
	        	$tagId = $existingtag["id"];
	        }

            if (!$tagisset) {

                p("tag does not exist in workspace");
                unset($tag['created_at']);
                unset($tag['followers']);
                $tag['workspace'] = $newworkspaceId;

                $data = array('data' => $tag);
                $result = asanaRequest("tags", "POST", $data);
                if (isError($result))
				{
			        pre($result, "Failed to create tag in target workspace!", 'danger');
					return;
				}
                $tagId = $result["data"]["id"];

				// Cache new tag
				cache($tagkey, $result["data"]);
            }

            $data = array("data" => array("tag" => $tagId));
            $result = asanaRequest("tasks/$newTaskId/addTag", "POST", $data);
            if (isError($result))
			{
		        pre($result, "Failed to add tag to task!", 'danger');
				return;
			}
        }
    }
}

function getWorkspaces() {
	$result = asanaRequest("workspaces");
	if (isError($result))
	{
        pre($result, "Error Loading Workspaces!", 'danger');
		return;
	}

	return $result['data'];
}

function getWorkspace($workspaceId) {
	$result = asanaRequest("workspaces/$workspaceId");
	if (isError($result))
	{
        pre($result, "Error Loading Workspace!", 'danger');
		return;
	}

	return $result['data'];
}

function getTeams($organizationId) {
	$result = asanaRequest("organizations/$organizationId/teams");
	if (isError($result))
	{
        pre($result, "Error Loading Teams!", 'danger');
		return;
	}

	return $result['data'];
}

function getTeam($organizationId, $teamId) {
	$result = asanaRequest("teams/$teamId");
	if (isError($result))
	{
        pre($result, "Error Loading Team!", 'danger');
		return;
	}

	return $result['data'];
}

function getProjects($workspaceId) {
	$result = asanaRequest("workspaces/$workspaceId/projects");
	if (isError($result))
	{
        pre($result, "Error Loading Projects!", 'danger');
		return;
	}

	return $result['data'];
}

function getProject($projectId) {
	$result = asanaRequest("projects/$projectId");
	if (isError($result))
	{
        pre($result, "Error Loading Project!", 'danger');
		return;
	}

	return $result['data'];
}

function queueTasks($fromProjectId, $toProjectId, $offset = 0) {
	global $channel;
	global $authToken;
	global $DEBUG;

	// Start task
	require_once("google/appengine/api/taskqueue/PushTask.php");

	$params = [
		'channel' => $channel,
		'authToken' => $authToken,
		'fromProjectId' => $fromProjectId,
		'toProjectId' => $toProjectId,
		'offset' => $offset,
		'debug' => $DEBUG
	];
	$job = new \google\appengine\api\taskqueue\PushTask('/process/tasks', $params);
	$task_name = $job->add();
}

function copyTasks($fromProjectId, $toProjectId, $offset = 0)
{
    // GET Project
	$result = asanaRequest("projects/$toProjectId");
	if (isError($result))
	{
        pre($result, "Error Loading Project!", 'danger');
		return;
	}
    $workspaceId = $result['data']['workspace']['id'];

    // GET Project tasks
    // TODO once OAuth is used, add support for external field
    $result = asanaRequest("projects/$fromProjectId/tasks?opt_fields=assignee,assignee_status,completed,due_on,due_at,hearted,name,notes");
	$tasks = $result['data'];

	global $APPENGINE;

    // copy Tasks
    // TODO check timing and re-queue after 5mins
    for ($i = count($tasks) - 1; $i >= 0; $i--)
	{
		$task = $tasks[$i];

		$taskId = $task['id'];

		$newTask = $task;
		unset($newTask['id']);
		$newTask['assignee'] = $newTask['assignee']['id'];
		foreach ($newTask as $key => $value)
		{
			if (empty($value))
			{
				unset($newTask[$key]);
			}
		}

		$newTask = createTask($workspaceId, $toProjectId, $newTask);
		
		if ($APPENGINE) {
			queueTask($workspaceId, $taskId, $newTask);
		}
		else {
			copyTask($workspaceId, $taskId, $newTask);
		}
	}
}

function queueTask($workspaceId, $taskId, $newTask) {
	global $channel;
	global $authToken;
	global $DEBUG;

	// Start task
	require_once("google/appengine/api/taskqueue/PushTask.php");

	$params = [
		'channel' => $channel,
		'authToken' => $authToken,
		'copy' => 'task',
		'workspaceId' => $workspaceId,
		'taskId' => $taskId,
		'newTask' => $newTask,
		'debug' => $DEBUG
	];
	$job = new \google\appengine\api\taskqueue\PushTask('/process/task', $params);
	$task_name = $job->add();
}

function copyTask($workspaceId, $taskId, $newTask) {

    //copy history
    $newTaskId=$newTask['id'];
	copyHistory($taskId, $newTaskId);

    //copy tags
    copyTags($taskId, $newTaskId, $workspaceId);

    //implement copying of subtasks
    $depth = 0;
    copySubtasks($taskId, $newTaskId, $depth, $workspaceId);
}