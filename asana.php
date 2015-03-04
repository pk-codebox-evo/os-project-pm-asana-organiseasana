<?php

/**
 * This is based on the implementation here:
 * https://gist.github.com/AWeg/5814427
 */

// Utility functions
function p($text) {
	print "<p>" . $text . "</p>\n";
	flush();
}

function pre($o, $title = false, $style = 'info') {
	print '<div class="bs-callout bs-callout-' . $style . '">';
	if ($title)
		print "<h4>$title</h4>";
	print "<pre>";
	print(json_encode($o, JSON_PRETTY_PRINT));
	print "</pre></div>\n";
	flush();
}

function isError($result) {
	return isset($result['errors']) || !isset($result['data']);
}

function cleanTask($task) {
	global $DEBUG;
	if ($DEBUG) pre($task, "Cleaning task");
	// "message": ".assignee_status: Schedule status shouldn't be set for unassigned tasks"
	if (!$task['assignee']) {
		unset($task['assignee_status']);
		if ($DEBUG) pre($task, "Removed Assignee Status ('Schedule status shouldn't be set for unassigned tasks')", 'warn');
	}
	
	return $task;	
}
	
class Asana {
	public $apiKey;
	
	function __construct($apiKey) {
		$this->apiKey = $apiKey;
	}
		
	function request($methodPath, $httpMethod = 'GET', $body = null)
	{
		global $DEBUG;

		$url = "https://app.asana.com/api/1.0/$methodPath";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
		curl_setopt($ch, CURLOPT_USERPWD, $apiKey);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod);

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
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $jbody);
		}

		$data = curl_exec($ch);
		$error = curl_error($ch);
		curl_close($ch);

		$result = json_decode($data, true);

		if ($DEBUG >= 2) {
			pre(array('request' => $body, 'response' => $result), "$httpMethod " . $url);
		}
		return $result;
	}

	function getWorkspaces() {
		$result = $this->request("workspaces");
		if (isError($result))
		{
			pre($result, "Error Loading Workspaces!", danger);
			return;
		}

		return $result['data'];
	}

	function getWorkspace($workspaceId) {
		$result = $this->request("workspaces/$workspaceId");
		if (isError($result))
		{
			pre($result, "Error Loading Workspace!", 'danger');
			return;
		}

		return new Workspace($this, $result['data']);
	}
}

class Workspace {
	public $asana;
	public $data;
	
	function __construct($asana, $data) {
		$this->asana = $asana;
		$this->data = $data;
	}

	function getTeams() {
		$result = $this->asana->request("organizations/{$this->data->id}/teams");
		if (isError($result))
		{
			pre($result, "Error Loading Teams!", 'danger');
			return;
		}

		return $result['data'];
	}

	function getTeam($teamId) {
		$result = $this->asana->request("teams/$teamId");
		if (isError($result))
		{
			pre($result, "Error Loading Team!", 'danger');
			return;
		}

		return $result['data'];
	}

	function getProjects() {
		$result = $this->asana->request("workspaces/{$this->data->id}/projects");
		if (isError($result))
		{
			pre($result, "Error Loading Projects!", 'danger');
			return;
		}

		return $result['data'];
	}

	function getProject($projectId) {
		$result = $this->asana->request("projects/$projectId");
		if (isError($result))
		{
			pre($result, "Error Loading Project!", 'danger');
			return;
		}

		return new Project($this, $result['data']);
	}

	function createProject($name, $teamId, $notes)
	{
		$workspaceId = $this->workspace->data->id;
		
		p("Creating project: " . $name);
		$data = array('data' => array('name' => $name));
		if ($workspaceId)
			$data['data']['workspace'] = $workspaceId;
		if ($notes)
			$data['data']['notes'] = $notes;
		if ($teamId)
			$data['data']['team'] = $teamId;

		$result = $this->asana->request("projects", 'POST', $data);

		if (!isError($result))
		{
			$newProject = $result['data'];
			return new Project($this, $newProject);
		}
		else {
			pre($result, "Error creating project!", 'danger');
			return;
		}
	}
}

class Project {
	public $asana;
	public $workspace;
	public $data;
	
	function __construct($workspace, $data) {
		$this->asana = $workspace->asana;
		$this->workspace = $workspace;
		$this->data = $data;
	}
	
	function createTask($task)
	{
		$workspaceId = $this->workspace->data->id;
		$projectId = $this->data->id;
		
		p("Creating task: " . $task['name']);

		// Set projects
		$task['projects'] = array($projectId);
		
		// Validate task data
		$task = cleanTask($task);

		// Create new task
		$data = array('data' => $task);
		$result = $this->asana->request("workspaces/$workspaceId/tasks", 'POST', $data);

		// Try to remove assignee if an error is returned
		// TODO check assignee exists before submitting the request
		if (isError($result) && isset($task['assignee'])) {
			unset($task['assignee']);
			
			// Validate task data
			$task = cleanTask($task);
		
			$data = array('data' => $task);
			$result = $this->asana->request("workspaces/$workspaceId/tasks", 'POST', $data);
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
}

class CopyProject {

	public $from;
	public $to;

	function __construct($from, $to) {
		$this->from = $from;
		$this->to = $to;
	}
	
	function copyTasks($fromProjectId, $toProjectId)
	{
		// GET Project
		$result = $this->asana->request("projects/$toProjectId");
		if (isError($result))
		{
			pre($result, "Error Loading Project!", 'danger');
			return;
		}
		$workspaceId = $result['data']['workspace']['id'];

		// GET Project tasks
		$result = $this->asana->request("projects/$fromProjectId/tasks?opt_fields=assignee,assignee_status,completed,due_on,name,notes");
		$tasks = $result['data'];

		// copy Tasks
		for ($i = count($tasks) - 1; $i >= 0; $i--)
		{
			$task = $tasks[$i];
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

			if ($newTask['id'])
			{

				//copy history
				$taskId = $task['id'];
				$newTaskId = $newTask['id'];
				copyHistory($taskId, $newTaskId);

				//copy tags
				copyTags($taskId, $newTaskId, $workspaceId);

				//copy subtasks
				$failsafe = 0;
				copySubtasks($taskId, $newTaskId, $failsafe, $workspaceId);

			}
		}
	}

	function copyTags ($taskId, $newTaskId, $newworkspaceId) {

		// GET Tags
		$result = $this->asana->request("tasks/$taskId/tags");

		if (!isError($result))
		{ 	// are there any tags?
			$tags = $result["data"];
		
			if ($tags) {
				$result = $this->asana->request("workspaces/$newworkspaceId/tags");
				$existingtags = $result["data"];
		
				for ($i = count ($tags) - 1; $i >= 0; $i--) {

					$tag = $tags[$i];
					$tagName = $tag["name"];
					$tagisset = false;

					// does tag exist?
					for($j = count($existingtags) - 1; $j >= 0; $j--) {
						$existingtag = $existingtags[$j];

						if ($tagName == $existingtag["name"]) {
							$tagisset = true;
							$tagId = $existingtag["id"];
							break;
						}
					}

					if (!$tagisset) {

						p("tag does not exist in workspace");
						unset($tag['created_at']);
						unset($tag['followers']);
						$tag['workspace'] = $newworkspaceId;

						$data = array('data' => $tag);
						$result = $this->asana->request("workspaces/$newworkspaceId/tags", "POST", $data);
						$tagId = $result["data"]["id"];

					}

					$data = array("data" => array("tag" => $tagId));
					$result = $this->asana->request("tasks/$newTaskId/addTag", "POST", $data);

				}
			}
		}
	}

	function copySubtasks($taskId, $newTaskId, $failsafe, $workspaceId) {

		$failsafe++;
		if ($failsafe > 10) {
			return FALSE;
		}

		// GET subtasks of task
		$result = $this->asana->request("tasks/$taskId/subtasks");
		$subtasks = $result["data"];


		if ($subtasks){     // does subtask exist?
			for ($i= count($subtasks) - 1; $i >= 0; $i--) {

				$subtask = $subtasks[$i];
				$subtaskId = $subtask['id'];

				// get data for subtask
				$result = $this->asana->request("tasks/$subtaskId?opt_fields=assignee,assignee_status,completed,due_on,name,notes");
				$task = $result['data'];
				unset($task["id"]);
			
				p("&nbsp;&nbsp;Creating subtask: " . $task['name']);
			
				if (isset($task["assignee"]))
					$task["assignee"] = $task["assignee"]["id"];

				// create Subtask
				$data = array('data' => cleanTask($task));
				$result = $this->asana->request("tasks/$newTaskId/subtasks", 'POST', $data);
			
				// Try to remove assignee if an error is returned
				// TODO check assignee exists before submitting the request
				if (isError($result) && isset($task['assignee'])) {
					unset($task['assignee']);
					$data = array('data' => cleanTask($task));
					$result = $this->asana->request("workspaces/$workspaceId/tasks", 'POST', $data);
				}

				// add History
				$newSubId = $result["data"]["id"];
				copyHistory($subtaskId, $newSubId);

				//copy tags
				copyTags($subtaskId, $newSubId, $workspaceId);

				// subtask of subtask?
				copySubtasks($subtaskId, $result["data"]["id"], $failsafe);

			}
		}


	}

	function copyHistory($taskId, $newTaskId) {

		$result = $this->asana->request("tasks/$taskId/stories");
		$comments = array();
		foreach ($result['data'] as $story){
			$date = date('l M d, Y h:i A', strtotime($story['created_at']));
			$comment = " ­\n" . $story['created_by']['name'] . ' on ' . $date . ":\n" . $story['text'];
			$comments[] = $comment;
		}
		$comment = implode("\n----------------------", $comments);
		$data = array('data' => array('text' => $comment));
		$result = $this->asana->request("tasks/$newTaskId/stories", 'POST', $data);

	}
}