<?php
use Aws\Credentials\Credentials;
use Aws\CostExplorer\CostExplorerClient;

try {
    require 'vendor/autoload.php';
    $config = require 'config.php';

    $today = (new DateTime())->format('Y-m-d');
    $yesterday = (new DateTime())->sub(new DateInterval('P1D'))->format('Y-m-d');

    $bot = new Telegram($config['BOT_TOKEN']);
    $costExplorer = new CostExplorerClient([
        'credentials' => new Credentials($config['AWS_KEY'], $config['AWS_SECRET']),
        'region' => 'us-east-1',
        'version' => 'latest'
    ]);

    $costData = getCostData($yesterday, $today, $costExplorer);
    $processedData = processCostData($costData);
    $text = formatCostData($processedData, $yesterday);
    $result = notify($config['NOTIFY_GROUP_ID'], $text, $bot);
    if (!$result['ok']) {
        throw new RuntimeException(sprintf('Failed to send message %s to group %s. %s', $text, $config['NOTIFY_GROUP_ID'], print_r($result, true)));
    }
} catch (Exception $e) {
    logError($e->getMessage() . PHP_EOL . $e->getTraceAsString());
}

function getCostData($startDate, $endDate, CostExplorerClient $client)
{
    $result = $client->getCostAndUsage([
        'TimePeriod' => [
            'Start' => $startDate,
            'End' => $endDate,
        ],
        'Granularity' => 'DAILY',
        'GroupBy' => [
            [
                'Key' => 'SERVICE',
                'Type' => 'DIMENSION',
            ],
        ],
        'Metrics' => ['UnblendedCost'],
    ])->toArray();

    if (!isset($result['ResultsByTime'][0]['Groups'])) {
        throw new RuntimeException(sprintf('Unexpected result %s', print_r($result, true)));
    }

    return $result['ResultsByTime'][0]['Groups'];
}

function processCostData(array $costData)
{
    $result = [];
    foreach ($costData as $group) {
        $amount = round($group['Metrics']['UnblendedCost']['Amount']);
        if ($amount < 10) {
            continue;
        }

        $result[] = [
            'service' => $group['Keys'][0],
            'amount' => $amount
        ];
    }

    usort($result, function ($a , $b) {
        return -1 * ($a['amount'] <=> $b['amount']);
    });

    return $result;
}

function formatCostData($services, $date)
{
    $text = sprintf('Spends on *%s*:' . PHP_EOL, $date);
    foreach ($services as $service) {
        $text .= sprintf('*%s*: %s$' . PHP_EOL, $service['service'], $service['amount']);
    }
    $text .= PHP_EOL . 'Go to [AWS Cost explorer](https://console.aws.amazon.com/cost-reports/home?#/custom?type=daily&groupBy=Service&forecastTimeRangeOption=None&hasBlended=false&excludeTaggedResources=false&chartStyle=Stack&timeRangeOption=Last7Days&granularity=Daily&reportName=Daily%20costs&isTemplate=true&reportType=CostUsage&hasAmortized=false&excludeDiscounts=true&usageAs=usageQuantity)';

    return $text;
}

function notify($group, $text, Telegram $bot)
{
    return $bot->sendMessage([
        'text' => $text,
        'chat_id' => $group,
        'parse_mode' => 'Markdown'
    ]);
}

function logError($errorText)
{
    file_put_contents('errors.txt', sprintf("%s:\n%s\n\n", date('Y-m-d H:i:s'), $errorText),FILE_APPEND);
    return true;
}