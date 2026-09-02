<?php
function i18n_current_lang(): string { $lang=$_GET['lang']??($_COOKIE['lszj_lang']??'de'); return in_array($lang,['de','fr'],true)?$lang:'de'; }
function t(string $key, ?string $lang=null): string { try { $pdo=db(); $lang=$lang?:i18n_current_lang(); $stmt=$pdo->prepare("SELECT translation_text FROM i18n_translations WHERE translation_key=? AND lang=?"); $stmt->execute([$key,$lang]); $v=$stmt->fetchColumn(); return $v?:$key; } catch(Throwable $e) { return $key; } }
