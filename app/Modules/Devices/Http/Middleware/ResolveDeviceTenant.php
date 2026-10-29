<?php

namespace App\Modules\Devices\Http\Middleware;

use App\Modules\Core\Models\Company;
use App\Modules\Devices\Models\AttendanceDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The serial is the credential — docs/saas-launch-plan.md §8.7. An unregistered
 * or inactive serial gets a 404 and nothing stored: an endpoint that
 * acknowledges an unknown device teaches a scanner it exists. The company is
 * current for the request and forgotten after it, like every public door here.
 */
class ResolveDeviceTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = AttendanceDevice::findBySerial($request->query('SN'));

        abort_unless($device, 404);

        // Seen, whatever it came to say: a quiet device looks exactly like a quiet office.
        $device->forceFill(['last_seen_at' => now(), 'silence_notified_at' => null])->save();

        $device->company->makeCurrent();

        try {
            $request->attributes->set('attendanceDevice', $device);

            return $next($request);
        } finally {
            Company::forgetCurrent();
        }
    }
}
