<?php defined("SYSPATH") or die("No direct script access.") ?>
<html>
  <head>
    <title><?= HTML::clean($subject) ?> </title>
  </head>
  <body>
    <h2><?= HTML::clean($subject) ?></h2>
    <table>
      <tr>
        <td><?= t("Title:") ?></td>
        <td><?= HTML::purify($item->title) ?></td>
      </tr>
      <tr>
        <td><?= t("Url:") ?></td>
        <td>
          <a href="<?= $item->abs_url() ?>">
            <?= $item->abs_url() ?>
          </a>
        </td>
      </tr>
      <? if ($item->description): ?>
      <tr>
        <td><?= t("Description:") ?></td>
         <td><?= nl2br(HTML::purify($item->description)) ?></td>
      </tr>
      <? endif ?>
    </table>
  </body>
</html>
