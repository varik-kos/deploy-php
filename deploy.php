<?php

class Deploy
{
    /**
     * A callback function to call after the deploy has finished.
     *
     * @var callback
     */
    public $post_deploy;
    /**
     * The name of the file that will be used for logging deployments. Set to
     * FALSE to disable logging.
     *
     * @var array
     */
    private $_log = [];
    /**
     * The timestamp format used for logging.
     *
     * @link    http://www.php.net/manual/en/function.date.php
     * @var     string
     */
    private $_date_format = 'Y-m-d H:i:sP';
    /**
     * The name of the branch to pull from.
     *
     * @var string
     */
    private $_branch = 'master';
    /**
     * The name of the remote to pull from.
     *
     * @var string
     */
    private $_remote = 'origin';
    /**
     * The email address to send report to.
     *
     * @var string
     */
    private $_email = '';
    /**
     * The directory where your website and git repository are located, can be
     * a relative or absolute path
     *
     * @var string
     */
    private $_directory;

    /**
     * Sets up defaults.
     *
     * @param string $directory Directory where your website is located
     * @param array $data Information about the deployment
     */
    public function __construct($directory, $options = array())
    {
        $this->_directory = realpath($directory) . DIRECTORY_SEPARATOR;

        $available_options = array('date_format', 'branch', 'remote', 'email');

        foreach ($options as $option => $value) {
            if (in_array($option, $available_options) && !empty($value)) {
                $this->{'_' . $option} = $value;
            }
        }

        $this->log('Attempting deployment to server "' . $_SERVER['SERVER_NAME'] . '" from "' . $this->_branch . '" branch...');
    }

    /**
     * Writes a message to the log file.
     *
     * @param string $message The message to write
     * @param string $type The type of log message (e.g. INFO, DEBUG, ERROR, etc.)
     */
    public function log($message, $type = 'INFO')
    {
        $message = date($this->_date_format) . ' --- ' . $type . ': ' . $message;

        $this->_log[] = $message;
    }

    /**
     * Executes a command
     *
     * @param mixed $cmd
     * @param mixed $cwd
     * @return string
     */
    protected function syscall($cmd)
    {
        $descriptorspec = array(
            1 => array('pipe', 'w'), // stdout is a pipe that the child will write to
            2 => array('pipe', 'w') // stderr
        );

        $resource = proc_open($cmd . ' 2>&1', $descriptorspec, $pipes, $this->_directory);

        if (is_resource($resource)) {
            $output = stream_get_contents($pipes[2]);
            $output .= PHP_EOL;
            $output .= stream_get_contents($pipes[1]);
            $output .= PHP_EOL;

            fclose($pipes[1]);
            fclose($pipes[2]);

            proc_close($resource);
            return $output;
        }

        return '';
    }

    /**
     * Summary of sendReport
     *
     * @return boolean
     */
    protected function sendReport()
    {
        $subject = 'MONITOR: Deployment to server "' . $_SERVER['SERVER_NAME'] . '" from "' . $this->_branch . '" branch';

        $message = '<p>Deployment attempt log: </p>' . PHP_EOL;

        if (count($this->_log) > 0) {
            $content = implode(PHP_EOL, $this->_log);
            $content = nl2br($content);

            $message .= '<p>' . $content . '</p>';
        } else {
            $message .= '<p>No log data available</p>';
        }

        $headers = "Content-type: text/html; charset=utf-8 \r\n";
        $headers .= "From: NoReply <info@site.com>\r\n";
        //$headers .= "Reply-To: reply-to@example.com\r\n";

        mail($this->_email, $subject, $message, $headers);

        return true;
    }

    /**
     * Executes the necessary commands to deploy the website.
     */
    public function execute()
    {
        $entityBody = file_get_contents('php://input');

        $repoName = '';
        $actor = '';
        $commits = [];

        if (!empty($entityBody)) {
            $data = json_decode($entityBody);

            $repoName = $data->repository->full_name;

            $actor = $data->actor->display_name . ' <' . $data->actor->username . '>';

            for ($cnt = 0; $cnt < count($data->push->changes); $cnt++) {
                $update = $data->push->changes[$cnt];

                if ($update->new->type == 'branch' && $update->new->name == $this->_branch) {
                    for ($cnt2 = 0; $cnt2 < count($update->commits); $cnt2++) {
                        if ($update->commits[$cnt2]->type == 'commit') {
                            $commit = new stdClass();

                            $commit->author = $update->commits[$cnt2]->author->raw;
                            $commit->message = $update->commits[$cnt2]->message;
                            $commit->date = $update->commits[$cnt2]->date;

                            $date = new DateTime($update->commits[$cnt2]->date);
                            $date->setTimezone(new DateTimeZone('Europe/Minsk'));

                            $commit->date = $date->format($this->_date_format);

                            $commits[] = $commit;
                        }
                    }
                }
            }
        } else {
            $this->log('No commit data in request. Potential request from broswer');
            echo "No commit data. Exit.";

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $this->sendReport();

            return;
        }

        $this->log('Receiving commit from [' . $actor . '] to repository [' . $repoName . ']... ');

        if (count($commits) == 0) {
            $this->log('No commits catched to [' . $this->_branch . '].');
        } else {
            foreach ($commits as $commit) {
                $this->log('Catched commit by [' . $commit->author . '] at [' . $commit->date . '] with message [' . $commit->message . ']');
            }

            try {
                // Make sure we're in the right directory
                $output = '';
                $output = $this->syscall('cd ' . $this->_directory);
                $this->log('Changing working directory... ');
                $this->log($output);

                // Checking any changes to tracked files since our last deploy
                $output = $this->syscall('git status');
                $this->log('Checking changes... ');
                $this->log($output);

                // Discard any changes to tracked files since our last deploy
                //exec('git reset --hard origin/'.$this->_branch, $output);
                $output = $this->syscall('git checkout -f');
                $this->log('Reseting repository... ');
                $this->log($output);

                // Discard any changes to tracked files since our last deploy
                $output = $this->syscall('git fetch');
                $this->log('Fetch code from repository... ');
                $this->log($output);

                // Update the local repository
                $output = $this->syscall('git pull');
                $this->log('Pulling in changes... ');
                $this->log($output);

                if (is_callable($this->post_deploy)) {
                    call_user_func($this->post_deploy, $output);
                }

                $this->log('Deployment successful.');
            } catch (Exception $e) {
                $this->log($e, 'ERROR');
            }
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $this->sendReport();
    }
}

/**
 * Execution
 */
define('PROJECT_ROOT', dirname(__FILE__));

$defTimezone = 'Europe/Minsk';

$params = array();
$paramsFile = __DIR__ . '/deploy_conf.php';

if (file_exists($paramsFile)) {
    $params = require($paramsFile);
}

if (!empty($params['timezone'])) {
    $defTimezone = $params['timezone'];
}

date_default_timezone_set($defTimezone);

if (defined('PROJECT_ROOT') && is_dir(PROJECT_ROOT)) {
    $deploy = new Deploy(PROJECT_ROOT, $params);
}

$deploy->execute();

?>
