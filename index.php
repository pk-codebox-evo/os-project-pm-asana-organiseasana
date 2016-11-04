<?php

	include "init.php";
	include "asana.php";

	global $pusher;
	$pusher = false;

	// Input parameters
	$storeKey = null;
	$targetWorkspaceId = null;
	$copy = null;
	$workspaceId = null;
	$projects = null;
	$teamId = null;
	$refresh = false;

	// Has the job been cancelled?
	if (isset($_POST["cancel"]) && $channel) {
		cancel($channel);
		return;
	}

	// Read parameters
	if (isset($_POST["storeKey"]))
		$storeKey = $_POST["storeKey"];
	if (isset($_POST["refresh"]))
		$channel = $_POST['refresh'];

	if (isset($_POST["new_workspace"])) {
		$workspaceId = $_POST["new_workspace"];
	}
	else {
		if (isset($_POST["workspace"]))
			$workspaceId = $_POST["workspace"];

		if (isset($_POST["projects"]))
			$projects = $_POST["projects"];

		if (isset($_POST["new_targetWorkspace"])) {
			$targetWorkspaceId = $_POST["new_targetWorkspace"];
		}
		else {
			if (isset($_POST["targetWorkspace"]))
				$targetWorkspaceId = $_POST["targetWorkspace"];
			if (isset($_POST["copy"]))
				$copy = $_POST["copy"];
			if (isset($_POST["team"]))
				$teamId = $_POST["team"];
		}
	}

if($DEBUG >= 1) {
?>
<div class="bs-callout bs-callout-info">
	<h4>DEBUG Mode is ON</h4>
	<a href="?debug=0" class="btn btn-warning">Disable</a>

	<div class="btn-group">
		<a href="?debug=1" class="btn btn-default">Level 1</a>
		<a href="?debug=2" class="btn btn-default">Level 2 (show API calls)</a>
	</div>
</div> <?php 
	if($DEBUG >= 3) { ?>
<div class="bs-callout bs-callout-info">
	<?php 
	print "<h4>Parameters</h4>";
	print "<pre>";
	print(json_encode(array(
		"APPENGINE" => $APPENGINE, 
		"DEBUG" => $DEBUG,
		"authToken" => $authToken
		), JSON_PRETTY_PRINT));
	print "</pre>\n";
	flush(); ?>
</div>
<?php
	}
}

?>
<html>
	<head>
		<title>Organise Asana Projects</title>
		<script src="//code.jquery.com/jquery-1.11.0.min.js"></script>
		<script src="/jquery.form.js"></script>

		<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap.min.css">
		<link rel="stylesheet" href="/theme.min.css">
		<script src="//netdna.bootstrapcdn.com/bootstrap/3.1.1/js/bootstrap.min.js"></script>
		<link rel="shortcut icon" href="/favicon.ico">
		<style>
			form input[type=text] { width: 500px; }

			.bs-callout h4 {
				margin-top: 0;
				margin-bottom: 5px;
			}
			.bs-callout-info h4 {
				color: #5bc0de;
			}
			.bs-callout-warning h4 {
				color: #f0ad4e;
			}
			.bs-callout-danger h4 {
				color: #d9534f;
			}

			.bs-callout {
				margin: 20px 0;
				padding: 20px;
				border-left: 3px solid #eee;
			}
			.bs-callout-info {
				background-color: #f4f8fa;
				border-color: #5bc0de;
			}
			.bs-callout-warning {
				background-color: #fcf8f2;
				border-color: #f0ad4e;
			}
			.bs-callout-danger {
				background-color: #fdf7f7;
				border-color: #d9534f;
			}

			#log {
				height: 400px;
				max-height: 400px;
				overflow-y: auto;
			}
		</style>
	</head>
	<body>
		<div class="container">
			<div class="page-header">
				<h1>Organise Asana Projects <small><a href="http://kothar.net/projects/organise-asana.html">Help!</a></small></h1>
			</div>
			<p class="lead">
				Copy <a href="https://asana.com" target="asana">Asana</a> projects from one workspace to another.
			</p>

			<?php $enabled = true; 
				if (!$enabled) {
			?>
			<div class="bs-callout bs-callout-warning">
				<h4>14th October 2016 - Service Delays</h4>
				<p>There is currently a large backlog of tasks in the queue (the oldest tasks waiting are about 5 hours old). This may be due to high latency communicating with Asana, or just a lot
				of people trying or retrying their copy operations.</p>
				<p>To let the backlog clear I've turned up the rate of task processing and temporarily disabled new jobs - sorry for the inconvenience. I should be able to turn it back on tomorrow.</p>
			</div>
			<?php } ?>

			<p>
				<b>Update 24th March 2016:</b> Asana have discontinued the supply of API keys, so I've implemented the proper OAuth login flow. Sorry it took so long!
			</p>
			<p>
				<b>Update 24th April 2016:</b> Copy jobs for large projects which take over 1 hour should now work without complaining about the OAuth tokens expiring.
			</p>
			<p>
				<b>Update 8th August 2016:</b> Attachments are now copied to the new workspace.
			</p>
			<form id="mainForm" role="form" method="POST">
				<div class="row">
					<div class="col-sm-8">
						<?php if ($authToken) { ?>
							<a href="/deauth" class="btn btn-danger">Log out</a>
						<?php } else { ?>
							<a href="/auth" class="btn btn-primary">Login with Asana</a>
						<?php } ?>
					</div>
				</div>

				<?php if ($authToken) {

					$targetWorkspace = null;
					$team = null;

					if ($targetWorkspaceId) {
						$targetWorkspace = getWorkspace($targetWorkspaceId);
					}

					if ($DEBUG) pre($targetWorkspace, "Target Workspace");

					// Do we have what we need to run a copy?
					if ($targetWorkspaceId && $projects) {
						if (isOrganisation($targetWorkspace)) {
							if ($teamId) {
								$team = getTeam($targetWorkspaceId, $teamId);
								if ($DEBUG) pre($team, "Team");
								if ($team)
									$copy = true;
							}
						}
					}

					// Run the copy operation
					if ($copy) {

						if ($APPENGINE) {
							// Create a pusher channel
							$channel = sha1(openssl_random_pseudo_bytes(30));

							// Start task
							require_once("google/appengine/api/taskqueue/PushTask.php");

							$params = [
								'channel' => $channel,
								'authToken' => $authToken,
								'debug' => $DEBUG,
								'targetWorkspace' => $targetWorkspaceId,
								'copy' => 'projects',
								'workspace' => $workspaceId,
								'projects' => $projects,
								'team' => $teamId
							];
							$task = new \google\appengine\api\taskqueue\PushTask('/process/project', $params);
							$task_name = $task->add();

							// Output script for listening to channel
							?>

							<h3 id="progress">Progress: 
								<input type="hidden" name="channel" value="<?php echo $channel; ?>">
								<input type="hidden" name="cancel" value="1">
								<input class="btn btn-danger pull-right" type="submit" value="Cancel" >
							</h3>

							<div class="well" id="log">
								Waiting in queue...<br>
							</div>
							<h3>New projects:</h3>
							<div id="projects"></div>
							<hr>
							<script src="//js.pusher.com/2.2/pusher.min.js"></script>
							<script>
								var pusher = new Pusher("<?php echo $config['pusher_key']; ?>");
								var channel = pusher.subscribe("<?php echo $channel; ?>");
								channel.bind('progress', function(data) {
								  var message = data.message;
								  $('#log').append(message + "<br>");
								  $('#log').scrollTop(10000000);
								});
								channel.bind('created', function(project) {
								  $('#projects').append('<a class="btn btn-success btn-xs" target="asana" href="https://app.asana.com/0/' + project['id'] + '">' + project['name'] + '</a> ');
								});
								channel.bind('done', function(data) {
								  $('#projects').append("<hr>Done.");
								  pusher.unsubscribe("<?php echo $channel; ?>");
								});
								channel.bind('error', function(data) {
								  var message = JSON.stringify(data.api_response, null, 2);
								  $('#log').append('<h2>' + data.error + '</h2><pre class="text-danger">' + message + "</pre><br>");
								  $('#log').scrollTop(10000000);
								});

								$(function() { 
						            $('#mainForm').ajaxForm(function() { 
									  $('#log').append('<p class="text-danger">Cancelling job...</p><br>');
									  $('#log').scrollTop(10000000);
						            }); 
						        }); 
							</script>
							<?php
						}
						else {
							$teamName = '';
							if ($team)
								$teamName = '/' . $team['name'];
							echo '<h2>Copying Projects to '. $targetWorkspace['name'] . $teamName . '</h2>';

							$newProjects = array();

							for ($i = count($projects) - 1; $i >= 0; $i--) {
								$project = getProject($projects[$i]);
								$targetProjectName = $project['name'];
								$notes = $project['notes'];

								// Check for an existing project in the target workspace
								$targetProjects = getProjects($targetWorkspaceId);
								if ($DEBUG) pre($targetProjects);

								$count = 2;
								$found = false;
								do {
									$found = false;
									for ($j = 0; $j < count($targetProjects); $j++) {
										if (strcmp($targetProjects[$j]['name'], $targetProjectName) == 0) {
											$targetProjectName = $project['name'] . ' ' . $count++;
											$found = true;
											break;
										}
									}
								}
								while ($found == true && $count < 100);

								// Create target project
								echo '<h4>Copying ' . $project['name'] . ' to ' . $targetWorkspace['name'] . $teamName . '/' . $targetProjectName . '</h4>';
								flush();
								$targetProject = createProject($targetWorkspaceId, $targetProjectName, $teamId, $notes);
								$newProjects[] = $targetProject;

								// Run copy
								copyTasks($project['id'], $targetProject['id']);
							}

							echo '<b>Done</b>';

							echo '<h4>View in Asana</h4>';
							echo '<div class="btn-group">';
							for ($i = count($newProjects) - 1; $i >= 0; $i--) {
								$project = $newProjects[$i];
								echo '<a class="btn btn-success btn-xs" target="asana" href="https://app.asana.com/0/' . $project['id'] . '">' . $project['name'] . '</a>';
							}
							echo '</div>';
							
						}
					}

					// Display copy options
					else if ($enabled) {
						echo '<h2>Browse workspace</h2>';
						echo '<div class="btn-group">';
						$workspaces = getWorkspaces();
						for ($i = count($workspaces) - 1; $i >= 0; $i--)
						{
							$workspace = $workspaces[$i];
							$active = '';
							if ($workspace['id'] == $workspaceId)
								$active = ' active';
							echo '<button class="btn btn-default' . $active . '" type="submit" name="new_workspace" value="' . $workspace['id'] . '">' . $workspace['name'] . '</button>';
						}
						echo '</div>';

						if ($workspaceId) {
							echo '<input type="hidden" name="workspace" value="' . $workspaceId . '"></input>';

							// Select projects
							echo '<div class="row">';
							echo '<div class="col-sm-4">';
							echo '<h2>Copy Projects -></h2>';

							$workspaceProjects = getProjects($workspaceId);
							$names = function($value) { return $value['name']; };
							if (is_array($workspaceProjects)) {
								array_multisort(array_map($names, $workspaceProjects), SORT_DESC, $workspaceProjects);
							}

							echo '<div class="btn-group-vertical" data-toggle="buttons">';
							for ($i = count($workspaceProjects) - 1; $i >= 0; $i--)
							{
								$project = $workspaceProjects[$i];
								$checked = '';
								$active = '';
								if ($projects && in_array($project['id'], $projects)) {
									$checked = ' checked';
									$active = ' active';
								}

								echo '<label class="btn btn-default' . $active . '"><input type="checkbox" name="projects[]" value="'
										. $project['id'] . '"' . $checked . '> ' . $project['name'] . '</label>';
							}
							echo '</div>';

							if ($DEBUG) pre($projects, "Selected projects");
							if ($DEBUG) pre($workspaceProjects, "Workspace projects");
							echo '</div>';

							// Select workspace
							echo '<div class="col-sm-4">';
							echo '<h2>to Workspace</h2>';

							echo '<div class="btn-group-vertical">';
							for ($i = count($workspaces) - 1; $i >= 0; $i--)
							{
								$workspace = $workspaces[$i];

								$type = ' btn-default';
								if (isOrganisation($workspace))
									$type = ' btn-warning';

								$active = '';
								if ($targetWorkspaceId == $workspace['id'])
									$active = ' active';
								echo '<button class="btn' . $type . $active . '" type="submit" name="new_targetWorkspace" value="' . $workspace['id'] . '">' . $workspace['name'] . '</button>';
							}
							echo '</div>';

							if ($DEBUG) pre($workspaces, "Target workspaces");
							echo '</div>';

							// Select team
							if ($targetWorkspaceId) {
								echo '<div class="col-sm-4">';
								echo '<input type="hidden" name="targetWorkspace" value="' . $targetWorkspaceId . '"></input>';
								$showTeams = isOrganisation($targetWorkspace);

								// Handle Personal Projects
								if ($showTeams) {
									$teams = getTeams($targetWorkspaceId);
									
									echo '<h2>for team</h2>';

									echo '<div class="btn-group-vertical">';
									for ($i = count($teams) - 1; $i >= 0; $i--)
									{
										$team = $teams[$i];

										echo '<button class="btn btn-success" type="submit" name="team" value="' . $team['id'] . '">' . $team['name'] . '</button>';
									}
									echo '</div>';

									if ($DEBUG) pre($teams, "Available teams");
								}
								else {
									// GO button

									echo '<h2>Ready!</h2>';
									echo '<button class="btn btn-success" type="submit" name="copy" value="go">Go!</button>';

								}
								echo '</div>';
							}

							echo '</div>';
						}
					}

				} ?>
			</form>

			<a class="btn btn-primary btn-xs" href=".">Back to start</a>

			<div class="bs-callout bs-callout-info">
				<h4>Source code</h4>
				<p>Source code for this tool can be found at <a href="https://bitbucket.org/mikehouston/organiseasana">https://bitbucket.org/mikehouston/organiseasana</a></p>
				<p>The implementation of the copy operation is based on <a href="https://gist.github.com/AWeg/5814427">https://gist.github.com/AWeg/5814427</a></p>
				<h4>Privacy</h4>
				<p>No data is stored on the server - the API key is not retained between calls. No cookies are stored, unless you request the API key to be remembered.</p>
				<h4>No Warranty</h4>
				<p>This tool does not delete any data, and will not modifiy any existing projects (a new copy is made each time)</p>
				<p>No warranty is made however - use at your own risk</p>
			</div>
		</div>
	</body>
</html>