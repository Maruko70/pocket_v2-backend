<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogActivity
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only log for dashboard routes and non-GET requests (GET requests are just viewing)
        if (str_starts_with($request->path(), 'api/dashboard') 
            && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])
            && Auth::guard('sanctum')->check()) {
            $this->logActivity($request, $response);
        }

        return $response;
    }

    protected function logActivity(Request $request, $response)
    {
        try {
            $adminUser = Auth::guard('sanctum')->user();
            
            // Only log if user is admin (check if AdminUser model)
            if (!$adminUser || !($adminUser instanceof \App\Models\AdminUser)) {
                return;
            }

            $method = $request->method();
            $path = $request->path();
            
            // Determine action and model from route
            $action = $this->getAction($method);
            $modelType = $this->getModelType($path);

            if (!$action || !$modelType) {
                return;
            }

            $oldValues = null;
            $newValues = null;

            // Get old values for update/delete
            if (in_array($method, ['PUT', 'PATCH', 'DELETE']) && $request->route('id')) {
                $model = $this->getModel($modelType, $request->route('id'));
                if ($model) {
                    $oldValues = $model->toArray();
                }
            }

            // Get new values for create/update
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $newValues = $request->except(['password', '_token']);
            }

            ActivityLog::create([
                'admin_user_id' => $adminUser->id,
                'action' => $action,
                'model_type' => $modelType,
                'model_id' => $request->route('id'),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request->ip(),
                'description' => $this->getDescription($action, $modelType, $request),
            ]);
        } catch (\Exception $e) {
            // Silently fail logging to prevent breaking the response
            \Log::error('Activity log error: ' . $e->getMessage());
        }
    }

    protected function getAction($method)
    {
        return match($method) {
            'POST' => 'created',
            'PUT', 'PATCH' => 'updated',
            'DELETE' => 'deleted',
            default => null,
        };
    }

    protected function getModelType($path)
    {
        if (str_contains($path, 'doctors')) return 'Doctor';
        if (str_contains($path, 'complexes')) return 'Complex';
        if (str_contains($path, 'majors')) return 'Major';
        if (str_contains($path, 'availability')) return 'DoctorAvailability';
        if (str_contains($path, 'reservations')) return 'Reservation';
        return null;
    }

    protected function getModel($modelType, $id)
    {
        $modelClass = "App\\Models\\{$modelType}";
        if (class_exists($modelClass)) {
            return $modelClass::find($id);
        }
        return null;
    }

    protected function getDescription($action, $modelType, Request $request)
    {
        $adminUser = Auth::guard('sanctum')->user();
        $name = $adminUser->name ?? 'Admin';
        return "{$name} {$action} {$modelType}";
    }
}
