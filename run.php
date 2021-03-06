<?php

use GuillaumeGagnaire\Georide\API\Client as GeorideClient;
use GuillaumeGagnaire\Georide\API\Types\Tracker;
use Jmoati\FindMyPhone\Client as IcloudClient;
use Jmoati\FindMyPhone\Model\Device;
use Jmoati\FindMyPhone\Model\Location;
use karpy47\PhpMqttClient\MQTTClient;
use Symfony\Component\HttpClient\HttpClient;
use Jmoati\FindMyPhone\Model\Credential;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/secret.php';
require __DIR__.'/vendor/gboudreau/nest-api/nest.class.php';

$nest = new Nest(null, null, $issueToken, $cookie);

while (1) {
    $nestInfos = $nest->getDeviceInfo();

    $nestInfos = [
        'temperature' => round($nestInfos->current_state->temperature, 2),
        'humidity' => $nestInfos->current_state->humidity,
        'target_temperature' => $nestInfos->target->temperature,
        'heating' => $nestInfos->current_state->heat,

    ];

    $icloud = new IcloudClient(
        HttpClient::create(),
        new Credential($username, $icloudPassword)
    );

    $devices = $icloud->devices();

    $georide = new GeorideClient();
    $georide->user->login($username, $georidePassword);

    $trackers = $georide->user->getTrackers();

    $mqttClient = new MQTTClient($mqttHost, $mqttPort);
    $mqttClient->setAuthentication($mqttUsername, $mqttPassword);

    $success = $mqttClient->sendConnect('findmyphone');


    if ($success) {
        array_walk($devices, function (Device $device) use ($mqttClient) {
            if ($device->location instanceof Location) {
                $message = json_encode([
                    'longitude' => $device->location->longitude,
                    'gps_accuracy' => $device->location->accuracy,
                    'latitude' => $device->location->latitude,
                    'battery_level' => $device->batteryLevel,
                ]);

                $mqttClient->sendPublish(sprintf('location/%s', substr ($device->id , 0, 12)), $message);
            }
        });

        /** @var Tracker $tracker */
        $tracker = $trackers[0];

        $message = json_encode([
            'longitude' => $tracker->longitude,
            'latitude' => $tracker->latitude,
        ]);

        $mqttClient->sendPublish('location/forza', $message);

        $message = json_encode($nestInfos);
        $mqttClient->sendPublish('thing/nest', $message);

        $mqttClient->sendDisconnect();
    }
    $mqttClient->close();

    sleep(300);
}

