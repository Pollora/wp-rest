<?php

declare(strict_types=1);

/*
| The WordPress classes the package names, for tests that run without
| WordPress. Each keeps only what the tests read.
*/

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function __construct(public string $code = '', public string $message = '', public array $data = []) {}

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function get_param(string $key): mixed
        {
            return null;
        }
    }
}
