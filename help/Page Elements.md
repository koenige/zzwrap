<!--
# zzwrap module
# about page elements
#
# Part of »Zugzwang Project«
# https://www.zugzwang.org/modules/zzwrap
#
# @author Gustaf Mossakowski <gustaf@koenige.org>
# @copyright Copyright © 2024 Gustaf Mossakowski
# @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
#
-->

# Page Elements

Each `request`- or `make` script returns an array `$page`. This contains the content of
the resource returned to the browser.

## text

There should be some text.

    $page['text'] = 'Hello world.';

You can use `zzbrick` templates for outputting the text.

    $page['text'] = wrap_template('hello-world');
    
Probably, you’ve got some data from somewhere, e. g. the database:

    $sql = 'SELECT user_id, user FROM users ORDER BY user';
    $data = wrap_db_fetch($sql, 'user_id');
    $page['text'] = wrap_template('users', $data);

## title

For the `h1` heading, the `title` field from the `webpages` table is used. This title is
used for the `title` element of the HTML document, too. You can set your own title:

    $page['title'] = 'List of users';

You can translate the title:

    $page['title'] = wrap_text('List of users');

## breadcrumbs

Breadcrumbs are created from the hierarchy in the `webpages` table. If you use a
`request`-script, you can replace the last (placeholder) element of the pages with one
or more breadcrumbs.

    $page['breadcrumbs][]['title'] = 'List of users';

If you like to add more breadcrumbs before this, use

    $page['breadcrumbs][] = ['title' => 'Lists', 'url_path' => '../'];

You can use absolute or relative links here. Beware: If a request script is used in a 
different context than originally intended, this might link to some other page.

You can add a title attribute as well:

    $page['breadcrumbs][] = [
    	'title' => 'Lists', 'title_attr' => 'Show all lists',  'url_path' => '../'
    ];

If, for some reason, you just need plain HTML code, use

    $page['breadcrumbs][]['html'] = '<strong>Some HTML here.</strong>';

## link

Sets `link` elements in the page `head`.

## meta

Sets `meta` elements in the page `head`.

## query_strings

Allows to use query strings in URLs.

## status

HTTP status code, if different from `200`.

## content_type

Page content type, if different from `html`. Use values from `filetypes.cfg`.

## extra

Set further variables that are needed for later use.

## data

Raw data used by the script, for use in other contexts.
