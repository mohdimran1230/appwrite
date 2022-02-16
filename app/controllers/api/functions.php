<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Database\Validator\CustomId;
use Appwrite\Event\Event;
use Utopia\Database\Validator\UID;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Appwrite\Utopia\Response;
use Appwrite\Task\Validator\Cron;
use Utopia\App;
use Utopia\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Config\Config;
use Cron\CronExpression;
use Executor\Executor;
use Utopia\CLI\Console;
use Utopia\Validator\Boolean;

include_once __DIR__ . '/../shared/api.php';

App::post('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('Create Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/functions/create-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new CustomId(), 'Function ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('execute', [], new ArrayList(new Text(64)), 'An array of strings with execution permissions. By default no user is granted with any execute permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.')
    ->param('runtime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Execution runtime.')
    ->param('vars', [], new Assoc(), 'Key-value JSON object that will be passed to the function as environment variables.', true)
    ->param('events', [], new ArrayList(new WhiteList(array_keys(Config::getParam('events')), true)), 'Events list.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, 900), 'Function maximum execution time in seconds.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($functionId, $name, $execute, $runtime, $vars, $events, $schedule, $timeout, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $functionId = ($functionId == 'unique()') ? $dbForProject->getId() : $functionId;
        $function = $dbForProject->createDocument('functions', new Document([
            '$id' => $functionId,
            'execute' => $execute,
            'dateCreated' => time(),
            'dateUpdated' => time(),
            'status' => 'disabled',
            'name' => $name,
            'runtime' => $runtime,
            'deployment' => '',
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'schedulePrevious' => 0,
            'scheduleNext' => 0,
            'timeout' => $timeout,
            'search' => implode(' ', [$functionId, $name, $runtime]),
        ]));

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('List Functions')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/functions/list-functions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of functions to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the function used as the starting point for the query, excluding the function itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        if (!empty($cursor)) {
            $cursorFunction = $dbForProject->getDocument('functions', $cursor);

            if ($cursorFunction->isEmpty()) {
                throw new Exception("Function '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $response->dynamic(new Document([
            'functions' => $dbForProject->find('functions', $queries, $limit, $offset, [], [$orderType], $cursorFunction ?? null, $cursorDirection),
            'sum' => $dbForProject->count('functions', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_FUNCTION_LIST);
    });

App::get('/v1/functions/runtimes')
    ->groups(['api', 'functions'])
    ->desc('List the currently active function runtimes.')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listRuntimes')
    ->label('sdk.description', '/docs/references/functions/list-runtimes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_RUNTIME_LIST)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $runtimes = Config::getParam('runtimes');

        $runtimes = array_map(function ($key) use ($runtimes) {
            $runtimes[$key]['$id'] = $key;
            return $runtimes[$key];
        }, array_keys($runtimes));

        $response->dynamic(new Document([ 
            'sum' => count($runtimes),
            'runtimes' => $runtimes
        ]), Response::MODEL_RUNTIME_LIST);
    });

App::get('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Get Function')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/functions/get-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($functionId, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/functions/:functionId/usage')
    ->desc('Get Function Usage')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_FUNCTIONS)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d']), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($functionId, $range, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Registry\Registry $register */

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }
        
        $usage = [];
        if(App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];
            
            $metrics = [
                "functions.$functionId.executions", 
                "functions.$functionId.failures", 
                "functions.$functionId.compute"
            ];

            $stats = [];

            Authorization::skip(function() use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $limit, 0, ['time'], [Database::ORDER_DESC]);
    
                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match($period) { // convert period to seconds for unix timestamp math
                            '30m' => 1800,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => ($stats[$metric][$last]['date'] ?? \time()) - $diff, // time of last metric minus period
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }    
            });

            $usage = new Document([
                'range' => $range,
                'functionsExecutions' => $stats["functions.$functionId.executions"],
                'functionsFailures' => $stats["functions.$functionId.failures"],
                'functionsCompute' => $stats["functions.$functionId.compute"]
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_FUNCTIONS);
    });

App::put('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Update Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/functions/update-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('execute', [], new ArrayList(new Text(64)), 'An array of strings with execution permissions. By default no user is granted with any execute permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.')
    ->param('vars', [], new Assoc(), 'Key-value JSON object that will be passed to the function as environment variables.', true)
    ->param('events', [], new ArrayList(new WhiteList(array_keys(Config::getParam('events')), true)), 'Events list.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, 900), 'Maximum execution time in seconds.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->action(function ($functionId, $name, $execute, $vars, $events, $schedule, $timeout, $response, $dbForProject, $project, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Database\Document $project */
        /** @var Appwrite\Auth\User $user */

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $original = $function->getAttribute('schedule', '');
        $cron = (!empty($function->getAttribute('deployment', null)) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (!empty($function->getAttribute('deployment', null)) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'execute' => $execute,
            'dateUpdated' => time(),
            'name' => $name,
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'scheduleNext' => (int)$next,
            'timeout' => $timeout,
            'search' => implode(' ', [$functionId, $name, $function->getAttribute('runtime')]),
        ])));

        if ($next && $schedule !== $original) {
            ResqueScheduler::enqueueAt($next, Event::FUNCTIONS_QUEUE_NAME, Event::FUNCTIONS_CLASS_NAME, [
                'projectId' => $project->getId(),
                'webhooks' => $project->getAttribute('webhooks', []),
                'functionId' => $function->getId(),
                'userId' => $user->getId(),
                'executionId' => null,
                'trigger' => 'schedule',
            ]);  // Async task rescheduale
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::patch('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Update Function Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.deployments.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'updateDeployment')
    ->label('sdk.description', '/docs/references/functions/update-function-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->action(function ($functionId, $deploymentId, $response, $dbForProject, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Database\Document $project */

        $function = $dbForProject->getDocument('functions', $functionId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready', 400);
        }

        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'deployment' => $deployment->getId(),
            'scheduleNext' => (int)$next,
        ])));

        if ($next) { // Init first schedule
            ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                'projectId' => $project->getId(),
                'webhooks' => $project->getAttribute('webhooks', []),
                'functionId' => $function->getId(),
                'executionId' => null,
                'trigger' => 'schedule',
            ]);  // Async task rescheduale
        }

        // Enqueue a message to start the build
        Resque::enqueue(Event::BUILDS_QUEUE_NAME, Event::BUILDS_CLASS_NAME, [
            'projectId' => $project->getId(),
            'functionId' => $function->getId(),
            'deploymentId' => $deploymentId,
            'type' => BUILD_TYPE_DEPLOYMENT
        ]);

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::delete('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Delete Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->action(function ($functionId, $response, $dbForProject, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $deletes */

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if (!$dbForProject->deleteDocument('functions', $function->getId())) {
            throw new Exception('Failed to remove function from DB', 500);
        }

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $function)
        ;

        $response->noContent();
    });

App::post('/v1/functions/:functionId/deployments')
    ->groups(['api', 'functions'])
    ->desc('Create Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.deployments.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createDeployment')
    ->label('sdk.description', '/docs/references/functions/create-deployment.md')
    ->label('sdk.packaging', true)
    ->label('sdk.request.type', 'multipart/form-data')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('entrypoint', '', new Text('1028'), 'Entrypoint File.')
    ->param('code', [], new File(), 'Gzip file with your code package. When used with the Appwrite CLI, pass the path to your code directory, and the CLI will automatically package your code. Use a path that is within the current directory.', false)
    ->param('activate', false, new Boolean(true), 'Automatically activate the deployment when it is finished building.', false)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('user')
    ->inject('project')
    ->action(function ($functionId, $entrypoint, $file, $activate, $request, $response, $dbForProject, $usage, $user, $project) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $usage */
        /** @var Appwrite\Auth\User $user */
        /** @var Appwrite\Database\Document $project */

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $file = $request->getFiles('code');
        $device = Storage::getDevice('functions');
        $fileExt = new FileExt([FileExt::TYPE_GZIP]);
        $fileSize = new FileSize(App::getEnv('_APP_STORAGE_LIMIT', 0));
        $upload = new Upload();

        if (empty($file)) {
            throw new Exception('No file sent', 400);
        }

        // Make sure we handle a single file and multiple files the same way
        $file['name'] = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $file['tmp_name'] = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $file['size'] = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        if (!$fileExt->isValid($file['name'])) { // Check if file type is allowed
            throw new Exception('File type not allowed', 400);
        }

        if (!$fileSize->isValid($file['size'])) { // Check if file size is exceeding allowed limit
            throw new Exception('File size not allowed', 400);
        }

        if (!$upload->isValid($file['tmp_name'])) {
            throw new Exception('Invalid file', 403);
        }

        // Save to storage
        $size = $device->getFileSize($file['tmp_name']);
        $path = $device->getPath(\uniqid().'.'.\pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!$device->upload($file['tmp_name'], $path)) { // TODO deprecate 'upload' and replace with 'move'
            throw new Exception('Failed moving file', 500);
        }

        if ((bool) $activate) {
            // Remove deploy for all other deployments.
            $deployments = $dbForProject->find('deployments', [
                new Query('activate', Query::TYPE_EQUAL, [true]),
                new Query('resourceId', Query::TYPE_EQUAL, [$functionId]),
                new Query('resourceType', Query::TYPE_EQUAL, ['functions'])
            ]);

            foreach ($deployments as $deployment) {
                $deployment->setAttribute('activate', false);
                $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
            }
        }
        
        $deploymentId = $dbForProject->getId();
        $deployment = $dbForProject->createDocument('deployments', new Document([
            '$id' => $deploymentId,
            '$read' => ['role:all'],
            '$write' => ['role:all'],
            'resourceId' => $function->getId(),
            'resourceType' => 'functions',
            'dateCreated' => time(),
            'entrypoint' => $entrypoint,
            'path' => $path,
            'size' => $size,
            'search' => implode(' ', [$deploymentId, $entrypoint]),
            'activate' => ((bool) $activate === true),
        ]));

        // Enqueue a message to start the build
        Resque::enqueue(Event::BUILDS_QUEUE_NAME, Event::BUILDS_CLASS_NAME, [
            'projectId' => $project->getId(),
            'functionId' => $function->getId(),
            'deploymentId' => $deploymentId,
            'type' => BUILD_TYPE_DEPLOYMENT
        ]);

        $usage
            ->setParam('storage', $deployment->getAttribute('size', 0))
        ;


        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    });

App::get('/v1/functions/:functionId/deployments')
    ->groups(['api', 'functions'])
    ->desc('List Deployments')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listDeployments')
    ->label('sdk.description', '/docs/references/functions/list-deployments.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT_LIST)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of deployments to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the deployment used as the starting point for the query, excluding the deployment itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($functionId, $search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if (!empty($cursor)) {
            $cursorDeployment = $dbForProject->getDocument('deployments', $cursor);

            if ($cursorDeployment->isEmpty()) {
                throw new Exception("Deployment '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $queries[] = new Query('resourceId', Query::TYPE_EQUAL, [$function->getId()]);
        $queries[] = new Query('resourceType', Query::TYPE_EQUAL, ['functions']);

        $results = $dbForProject->find('deployments', $queries, $limit, $offset, [], [$orderType], $cursorDeployment ?? null, $cursorDirection);
        $sum = $dbForProject->count('deployments', $queries, APP_LIMIT_COUNT);

        foreach ($results as $result) {
            $build = $dbForProject->getDocument('builds', $result->getAttribute('buildId', ''));
            $result->setAttribute('status', $build->getAttribute('status', 'processing'));
            $result->setAttribute('buildStderr', $build->getAttribute('stderr', ''));
            $result->setAttribute('buildStdout', $build->getAttribute('stdout', ''));
        }

        $response->dynamic(new Document([
            'deployments' => $results,
            'sum' => $sum,
        ]), Response::MODEL_DEPLOYMENT_LIST);
    });

App::get('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Get Deployment')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getDeployment')
    ->label('sdk.description', '/docs/references/functions/get-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT_LIST)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($functionId, $deploymentId, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception('Deployment not found', 404);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    });

App::delete('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Delete Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.deployments.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'deleteDeployment')
    ->label('sdk.description', '/docs/references/functions/delete-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('deletes')
    ->action(function ($functionId, $deploymentId, $response, $dbForProject, $usage, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $usage */
        /** @var Appwrite\Event\Event $deletes */

        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }
        
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception('Deployment not found', 404);
        }

        if (!$dbForProject->deleteDocument('deployments', $deployment->getId())) {
            throw new Exception('Failed to remove deployment from DB', 500);
        }

        if($function->getAttribute('deployment') === $deployment->getId()) { // Reset function deployment
            $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
                'deployment' => '',
            ])));
        }

        $usage
            ->setParam('storage', $deployment->getAttribute('size', 0) * -1)
        ;

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $deployment)
        ;

        $response->noContent();
    });

App::post('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('Create Execution')
    ->label('scope', 'execution.write')
    ->label('event', 'functions.executions.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createExecution')
    ->label('sdk.description', '/docs/references/functions/create-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('data', '', new Text(8192), 'String of custom data to send to function.', true)
    ->param('async', true, new Boolean(), 'Execute code asynchronously. Default value is true.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('user')
    ->action(function ($functionId, $data, $async, $response, $project, $dbForProject, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Database\Document $user */

        $function = Authorization::skip(fn() => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $runtimes = Config::getParam('runtimes', []);

        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')])) ? $runtimes[$function->getAttribute('runtime', '')] : null;

        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported', 400);
        }

        $deployment = Authorization::skip(fn() => $dbForProject->getDocument('deployments', $function->getAttribute('deployment', '')));

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception('Deployment not found. Deploy deployment before trying to execute a function', 404);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found. Deploy deployment before trying to execute a function', 404);
        }

        /** Check if build has completed */
        $build = Authorization::skip(fn() => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));
        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready', 400);
        }

        $validator = new Authorization('execute');

        if (!$validator->isValid($function->getAttribute('execute'))) { // Check if user has write access to execute function
            throw new Exception($validator->getDescription(), 401);
        }

        $executionId = $dbForProject->getId();

        $execution = Authorization::skip(fn() => $dbForProject->createDocument('executions', new Document([
            '$id' => $executionId,
            '$read' => (!$user->isEmpty()) ? ['user:' . $user->getId()] : [],
            '$write' => [],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'deploymentId' => $deployment->getId(),
            'trigger' => 'http', // http / schedule / event
            'status' => 'waiting', // waiting / processing / completed / failed
            'statusCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'time' => 0.0,
            'search' => implode(' ', [$functionId, $executionId]),
        ])));

        $jwt = ''; // initialize
        if (!$user->isEmpty()) { // If userId exists, generate a JWT for function

            $sessions = $user->getAttribute('sessions', []);
            $current = new Document();

            foreach ($sessions as $session) { /** @var Utopia\Database\Document $session */
                if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $current = $session;
                }
            }

            if(!$current->isEmpty()) {
                $jwtObj = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.
                $jwt = $jwtObj->encode([
                    'userId' => $user->getId(),
                    'sessionId' => $current->getId(),
                ]);
            }
        }

        if ($async) {
            Resque::enqueue(Event::FUNCTIONS_QUEUE_NAME, Event::FUNCTIONS_CLASS_NAME, [
                'projectId' => $project->getId(),
                'functionId' => $function->getId(),
                'webhooks' => $project->getAttribute('webhooks', []),
                'executionId' => $execution->getId(),
                'trigger' => 'http',
                'data' => $data,
                'userId' => $user->getId(),
                'jwt' => $jwt,
            ]);

            $response->setStatusCode(Response::STATUS_CODE_CREATED);
            return $response->dynamic($execution, Response::MODEL_EXECUTION);
        }

        /** Collect environment variables */
        $vars = \array_merge($function->getAttribute('vars', []), [
            'APPWRITE_FUNCTION_ID' => $function->getId(),
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
            'APPWRITE_FUNCTION_TRIGGER' => 'http',
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
            'APPWRITE_FUNCTION_DATA' => $data,
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_USER_ID' => $user->getId(),
            'APPWRITE_FUNCTION_JWT' => $jwt,
        ]);

        /** Execute function */
        $executor = new Executor();
        $executionResponse = [];
        try {
            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                functionId: $function->getId(),
                deploymentId: $deployment->getId(),
                path: $build->getAttribute('outputPath', ''),
                vars: $vars,
                data: $data,
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                runtime: $function->getAttribute('runtime', ''),
                timeout: $function->getAttribute('timeout', 0),
                baseImage: $runtime['image']
            );

            /** Update execution status */
            $execution->setAttribute('status', $executionResponse['status']);
            $execution->setAttribute('statusCode', $executionResponse['statusCode']);
            $execution->setAttribute('stdout', $executionResponse['stdout']);
            $execution->setAttribute('stderr', $executionResponse['stderr']);
            $execution->setAttribute('time', $executionResponse['time']);
        } catch (\Throwable $th) {
            $execution->setAttribute('status', 'failed');
            $execution->setAttribute('statusCode', $th->getCode());
            $execution->setAttribute('stderr', $th->getMessage());
            Console::error($th->getMessage());
        }

        Authorization::skip(fn() => $dbForProject->updateDocument('executions', $executionId, $execution));
        $executionResponse['response'] = ($executionResponse['status'] !== 'completed') ? $executionResponse['stderr'] : $executionResponse['stdout'];

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document($executionResponse), Response::MODEL_SYNC_EXECUTION);
    });

App::get('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('List Executions')
    ->label('scope', 'execution.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listExecutions')
    ->label('sdk.description', '/docs/references/functions/list-executions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION_LIST)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('limit', 25, new Range(0, 100), 'Maximum number of executions to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('cursor', '', new UID(), 'ID of the execution used as the starting point for the query, excluding the execution itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($functionId, $limit, $offset, $search, $cursor, $cursorDirection, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $function = Authorization::skip(fn() => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if (!empty($cursor)) {
            $cursorExecution = $dbForProject->getDocument('executions', $cursor);

            if ($cursorExecution->isEmpty()) {
                throw new Exception("Execution '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [
            new Query('functionId', Query::TYPE_EQUAL, [$function->getId()])
        ];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $results = $dbForProject->find('executions', $queries, $limit, $offset, [], [Database::ORDER_DESC], $cursorExecution ?? null, $cursorDirection);
        $sum = $dbForProject->count('executions', $queries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'executions' => $results,
            'sum' => $sum,
        ]), Response::MODEL_EXECUTION_LIST);
    });

App::get('/v1/functions/:functionId/executions/:executionId')
    ->groups(['api', 'functions'])
    ->desc('Get Execution')
    ->label('scope', 'execution.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getExecution')
    ->label('sdk.description', '/docs/references/functions/get-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('executionId', '', new UID(), 'Execution ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($functionId, $executionId, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $function = Authorization::skip(fn() => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $execution = $dbForProject->getDocument('executions', $executionId);

        if ($execution->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Execution not found', 404);
        }

        if ($execution->isEmpty()) {
            throw new Exception('Execution not found', 404);
        }

        $response->dynamic($execution, Response::MODEL_EXECUTION);
    });

App::post('/v1/functions/:functionId/deployments/:deploymentId/builds/:buildId')
    ->groups(['api', 'functions'])
    ->desc('Retry Build')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.deployments.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'retryBuild')
    ->label('sdk.description', '/docs/references/functions/retry-build.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->param('buildId', '', new UID(), 'Build unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->action(function ($functionId, $deploymentId, $buildId, $response, $dbForProject, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Database\Document $project */

        $function = $dbForProject->getDocument('functions', $functionId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        $build = Authorization::skip(fn() => $dbForProject->getDocument('builds', $buildId));

        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404);
        }

        if ($build->getAttribute('status') !== 'failed') {
            throw new Exception('Build not failed', 400);
        }

        // Enqueue a message to start the build
        Resque::enqueue(Event::BUILDS_QUEUE_NAME, Event::BUILDS_CLASS_NAME, [
            'projectId' => $project->getId(),
            'functionId' => $function->getId(),
            'deploymentId' => $deploymentId,
            'type' => BUILD_TYPE_RETRY
        ]);

        $response->noContent();
    });