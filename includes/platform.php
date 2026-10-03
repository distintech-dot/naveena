<?php
/**
 * Tautan ke halaman PLATFORM VibeCoder (Pro / upgrade).
 *
 * Halaman Pro & pembayaran berada di DOMAIN PLATFORM, bukan di dalam folder
 * aplikasi maupun di akar subdomain pengguna. Sudah diuji langsung:
 *   https://vibecoder.co.id/pro.php                  → 200
 *   https://vibecoder.co.id/payment.php?buy=pro      → 200
 *   https://<subdomain>.vibecoder.co.id/pro.php      → 404  ← jangan dipakai
 * Tautan relatif biasa ("pro.php") juga salah karena akan dicari di
 * /<slug>/pro.php (dianggap halaman aplikasi yang tidak ada).
 */
const PLATFORM_BASE_URL = 'https://vibecoder.co.id';

function platform_link(string $path): string
{
    return PLATFORM_BASE_URL . '/' . ltrim($path, '/');
}
