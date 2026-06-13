<?php

return [

    /*
     * Voice Studio keeps an always-current training dataset directory after every
     * accepted recording. The nightly trainer uses that snapshot directly.
     */
    'training' => [
        'enabled' => env('VOICE_TRAIN_ENABLED', true),
        'command' => env('VOICE_TRAIN_COMMAND', base_path('../workers/tools/run_mms_vits_finetune.sh')),
        'window_start' => env('VOICE_TRAIN_WINDOW_START', '02:00'),
        'window_end' => env('VOICE_TRAIN_WINDOW_END', '06:00'),
        'max_load' => (float) env('VOICE_TRAIN_MAX_LOAD', 2.0),
        'min_clips' => (int) env('VOICE_TRAIN_MIN_CLIPS', 300),
        'min_new_clips' => (int) env('VOICE_TRAIN_MIN_NEW_CLIPS', 25),
        'epochs' => (int) env('VOICE_TRAIN_EPOCHS', 120),
        'batch_size' => (int) env('VOICE_TRAIN_BATCH_SIZE', 8),
        'learning_rate' => env('VOICE_TRAIN_LEARNING_RATE', '2e-5'),
    ],

];
