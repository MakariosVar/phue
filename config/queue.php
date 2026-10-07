<?php

declare(strict_types=1);

use function Phue\Core\env;

return [
    /*
     * Background jobs.
     *
     * 'sync' is the default on purpose. A queue that does nothing looks exactly like a queue that
     * works until production, where nothing is left to pick the jobs up. Running the job the
     * moment it is pushed puts the failure in front of the developer who is still looking at the
     * code. Set QUEUE_DRIVER=database once there is a worker to take work from.
     */
    'driver' => env('QUEUE_DRIVER', 'sync'),

    /*
     * Queue used when neither the caller nor the job's #[Job] attribute names one.
     */
    'queue' => env('QUEUE_DEFAULT_QUEUE', 'default'),

    /*
     * Attempts before a job is dead-lettered, and the curve between them.
     *
     * The first retry waits backoff_base seconds and each attempt after that doubles, capped at
     * backoff_max. Jitter spreads the retries of jobs that failed together, so a batch brought
     * down by one unavailable host does not come back in lockstep and take it down again.
     */
    'max_tries' => 3,
    'backoff_base' => 5,
    'backoff_max' => 600,
    'backoff_jitter' => true,

    /*
     * How long a worker may hold a job before another worker may take it over, in seconds.
     *
     * This is the only thing that recovers work from a killed worker: nobody observes the death,
     * the lease simply runs out. It is not a timeout — a worker cannot be interrupted from
     * in-process — so set it longer than the longest job, not shorter.
     */
    'lease_seconds' => 60,

    /*
     * Refused at push rather than stored and discovered at run time. A job larger than this is
     * almost always carrying a blob or a document where an identifier belonged.
     */
    'max_payload_bytes' => 65536,

    /*
     * Table used by the database driver for live jobs. Dead letters go to queue_jobs_failed.
     */
    'table' => 'queue_jobs',

    /*
     * Namespaces a job class may be loaded from. Empty means unrestricted.
     *
     * A stored payload names the class a worker will instantiate, so this list is what keeps a
     * tampered queue table from becoming arbitrary class instantiation. Set it in production; the
     * empty default is a convenience for getting started, not a recommendation.
     */
    'job_namespaces' => [],

    /*
     * Additional drivers, keyed by the name to use in 'driver' above. Each value must be a class
     * implementing Phue\Modules\Queue\QueueDriverInterface, so adding one is a config change
     * rather than an edit to the module.
     */
    'drivers' => [],
];