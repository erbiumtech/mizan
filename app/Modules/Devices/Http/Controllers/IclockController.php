<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Devices\Models\AttendanceDevice;
use App\Modules\Devices\Models\DevicePunch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What a ZKTeco in push mode expects of its server — docs/saas-launch-plan.md §8.7.
 *
 * Three calls: a handshake on GET cdata, punches on POST cdata?table=ATTLOG as
 * tab-separated lines, and GET getrequest for commands — always none. Everything
 * else the device may post (operation logs, photos) is answered OK and dropped.
 * Plain text throughout, because that is what the firmware parses.
 */
class IclockController extends Controller
{
    public function handshake(Request $request): Response
    {
        /** @var AttendanceDevice $device */
        $device = $request->attributes->get('attendanceDevice');

        $lines = [
            "GET OPTION FROM: {$device->serial_no}",
            'ATTLOGStamp=0',
            'OPERLOGStamp=0',
            'ATTPHOTOStamp=0',
            'ErrorDelay=30',
            'Delay=10',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            "TransFlag=TransData AttLog\tOpLog",
            'TimeZone='.(int) (config('devices.timezone_offset_hours', 5)),
            'Realtime=1',
            'Encrypt=None',
        ];

        return $this->text(implode("\n", $lines)."\n");
    }

    public function receive(Request $request): Response
    {
        /** @var AttendanceDevice $device */
        $device = $request->attributes->get('attendanceDevice');

        if (strtoupper((string) $request->query('table')) !== 'ATTLOG') {
            return $this->text('OK');
        }

        $stored = 0;

        foreach (preg_split('/\r\n|\r|\n/', (string) $request->getContent()) ?: [] as $line) {
            $punch = DevicePunch::parseLine($line);

            if ($punch === null) {
                continue;
            }

            try {
                DevicePunch::create($punch + ['serial_no' => $device->serial_no]);
                $stored++;
            } catch (UniqueConstraintViolationException) {
                // A re-sent punch — the device retries after a dropped connection. Already kept.
            }
        }

        return $this->text("OK: {$stored}");
    }

    public function commands(): Response
    {
        return $this->text('OK');
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
