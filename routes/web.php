<?php

use Illuminate\Support\Facades\Route;

Route::get('/', 'App\Http\Controllers\HomeController@welcome');
Route::get('/signin', 'App\Http\Controllers\AuthController@signin');
Route::get('/callback', 'App\Http\Controllers\AuthController@callback');
Route::get('/signout', 'App\Http\Controllers\AuthController@signout');
Route::get('/migrate', 'App\Http\Controllers\MigrationController@migration');

Route::post('/migrate/all', 'App\Http\Controllers\MigrationController@migrateAllFiles')->name('migrate.all');
Route::post('/migrate/one', 'App\Http\Controllers\MigrationController@migrateSingleFile')->name('migrate.one');
