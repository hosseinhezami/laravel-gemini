<?php

namespace HosseinHezami\LaravelGemini\Helpers;

use HosseinHezami\LaravelGemini\Exceptions\ValidationException;
use Illuminate\Support\Facades\View;

class BladeRenderer
{
    /**
     * Check if a view exists in the Laravel view system
     */
    public static function viewExists(string $view): bool
    {
        try {
            return View::exists($view);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Render a Blade template with optional data
     *
     * @throws ValidationException
     */
    public static function render(string $template, array $data = []): string
    {
        try {
            // Check if it's a view name exists
            if (self::viewExists($template)) {
                return View::make($template, $data)->render();
            }

            // Check if it's a file path ending with .blade.php
            if (str_ends_with($template, '.blade.php') && file_exists($template)) {
                // Register the view path temporarily
                $viewPath = dirname($template);
                $viewName = 'gemini_temp_'.md5($template);
                $fileName = basename($template, '.blade.php');

                // Add the path to view paths temporarily
                View::addLocation($viewPath);

                // Render the view
                return View::make($fileName, $data)->render();
            }

            // If no Blade detected, return as-is
            return $template;

        } catch (\Exception $e) {
            throw new ValidationException(
                'Failed to render Blade template: '.$e->getMessage()
            );
        }
    }
}
