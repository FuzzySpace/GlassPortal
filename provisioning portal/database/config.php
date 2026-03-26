<?php

/**
 * Database connection configuration
 *
 * HOW TO SET UP (local XAMPP / WAMP):
 *   1. Open phpMyAdmin (http://localhost/phpmyadmin) or HeidiSQL
 *   2. Click "Import" and select:  database/schema.sql
 *   3. Click "Go" — the database, tables and seed data are created automatically.
 *   4. Done. The default credentials below match what schema.sql creates.
 *
 * If you prefer to use root directly (XAMPP default: root / no password):
 *   Change 'username' => 'root'  and  'password' => ''
 */

return [

    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'provisioning_portal',

    // Credentials created by schema.sql's CREATE USER statement.
    // For local XAMPP/WAMP root access use: 'username' => 'root', 'password' => ''
    'username' => 'portal_user',
    'password' => 'PortalStrongPass!ChangeMe',

    'charset'  => 'utf8mb4',

];
