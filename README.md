AI Dialogue (mod_aidialogue)
============================

An activity where a student talks with an AI, turn by turn, about a topic. The AI checks the
conversation against criteria set by the teacher (Bloom's levels or custom ones). The student then
gets feedback, and the teacher gets a report and an AI-suggested grade to review.

Requirements
------------

* Moodle 5.3+
* An OpenAI-compatible chat completions endpoint, with an API key.

Installation
------------

1. Copy the `aidialogue` folder into the `public/mod/` directory of your Moodle code
   (`public/mod/aidialogue`). The folder must be named `aidialogue`.
2. Visit your site's admin notifications page to complete the installation.

Configuration
-------------

Go to Site administration > Plugins > Activity modules > AI Dialogue and set:

* **API base URL**: the base URL of the endpoint. `/chat/completions` is added automatically.
* **API key**: the key for the endpoint. It is sent as the Bearer token on every request.
* **Model**: the model name to request.
* **Maximum tokens**: the most tokens the AI may use in one reply (default 1024, minimum 256).
  Reasoning models spend part of this on hidden reasoning, so they need a higher value, for
  example 4096.

Limitations
-----------

* The activity is not supported in the Moodle app. Open it in a browser.
* The plugin has its own AI settings and does not use Moodle's core AI subsystem.

Licence
-------

This plugin is free software, licensed under the GNU General Public License version 3 or later.
See the `LICENSE` file for the full text.
