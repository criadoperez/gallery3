<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2013 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Gallery_Controller_Uploader extends Controller {
  public function index($id) {
    $item = ORM::factory("item", $id);
    Access::required("view", $item);
    Access::required("add", $item);
    if (!$item->is_album()) {
      $item = $item->parent();
    }

    print $this->_get_add_form($item);
  }

  public function start() {
    Access::verify_csrf();
    Batch::start();
  }

  public function add_photo($id) {
    $album = ORM::factory("item", $id);
    Access::required("view", $album);
    Access::required("add", $album);
    Access::verify_csrf();

    // The Flash uploader not call /start directly, so simulate it here for now.
    if (!Batch::in_progress()) {
      Batch::start();
    }

    $form = $this->_get_add_form($album);

    // Uploadify adds its own field to the form, so validate that separately.
    $file_validation = new Validation($_FILES);
    $file_validation->add_rules(
      "Filedata", "Upload::valid",  "Upload::required",
      "Upload::type[" . implode(",", LegalFile::get_extensions()) . "]");

    if ($form->validate() && $file_validation->validate()) {
      $temp_filename = Upload::save("Filedata");
      System::delete_later($temp_filename);
      try {
        $item = ORM::factory("Item");
        $item->name = substr(basename($temp_filename), 10);  // Skip unique identifier Kohana adds
        $item->title = Item::convert_filename_to_title($item->name);
        $item->parent_id = $album->id;
        $item->set_data_file($temp_filename);

        $path_info = @pathinfo($temp_filename);
        if (array_key_exists("extension", $path_info) &&
            LegalFile::get_movie_extensions($path_info["extension"])) {
          $item->type = "movie";
          $item->save();
          Log::success("content", t("Added a movie"),
                       HTML::anchor("movies/$item->id", t("view movie")));
        } else {
          $item->type = "photo";
          $item->save();
          Log::success("content", t("Added a photo"),
                       HTML::anchor("photos/$item->id", t("view photo")));
        }

        Module::event("add_photos_form_completed", $item, $form);
      } catch (Exception $e) {
        // The Flash uploader has no good way of reporting complex errors, so just keep it simple.
        Log::add("error", $e->getMessage() . "\n" . $e->getTraceAsString());

        // Ugh.  I hate to use instanceof, But this beats catching the exception separately since
        // we mostly want to treat it the same way as all other exceptions
        if ($e instanceof ORM_Validation_Exception) {
          Log::add("error", "Validation errors: " . print_r($e->validation->errors(), 1));
        }

        header("HTTP/1.1 500 Internal Server Error");
        print "ERROR: " . $e->getMessage();
        return;
      }
      print "FILEID: $item->id";
    } else {
      header("HTTP/1.1 400 Bad Request");
      print "ERROR: " . t("Invalid upload");
    }
  }

  public function status($success_count, $error_count) {
    if ($error_count) {
      // The "errors" won't be properly pluralized :-/
      print t2("Uploaded %count photo (%error errors)",
               "Uploaded %count photos (%error errors)",
               (int)$success_count,
               array("error" => (int)$error_count));
    } else {
      print t2("Uploaded %count photo", "Uploaded %count photos", $success_count);}
  }

  public function finish() {
    Access::verify_csrf();

    Batch::stop();
    JSON::reply(array("result" => "success"));
  }

  private function _get_add_form($album)  {
    $form = new Forge("uploader/finish", "", "post", array("id" => "g-add-photos-form"));
    $group = $form->group("add_photos")
      ->label(t("Add photos to %album_title", array("album_title" => HTML::purify($album->title))));
    $group->uploadify("uploadify")->album($album);

    $group_actions = $form->group("actions");
    $group_actions->uploadify_buttons("");

    $inputs_before_event = array_keys($form->add_photos->inputs);
    Module::event("add_photos_form", $album, $form);
    $inputs_after_event = array_keys($form->add_photos->inputs);

    // For each new input in add_photos, attach JS to make uploadify update its value.
    foreach (array_diff($inputs_after_event, $inputs_before_event) as $input) {
      if (!$input) {
        // Likely a script input - don't do anything with it.
        continue;
      }
      $group->uploadify->script_data($input, $group->{$input}->value);
      $group->script("")
        ->text("$('input[name=\"$input\"]').change(function (event) {
                  $('#g-uploadify').uploadifySettings('scriptData', {'$input': $(this).val()});
                });");
    }

    return $form;
  }
}