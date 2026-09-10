<?php

$host = "localhost";
$username = "admin";
$password = "";
$database = "swapify";

$connection = mysqli_connect($host, $username, $password, $database);

if (!$connection) {
    die("Database connection failed: " . mysqli_connect_error());
}