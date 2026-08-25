/**
 * The ScholarZim bundle.
 *
 * Every page-specific script guards on the elements it needs and does nothing
 * when they are absent, so shipping them together costs one small request
 * instead of one per page, and there is no per-page @push to keep in step.
 *
 * theme-toggle.js is deliberately NOT here: it has to run before first paint to
 * avoid a flash of the wrong theme, so it stays a render-blocking script in the
 * document head rather than a deferred module.
 */
import './scholarzim';
import './application-review';
import './profile-form';
import './scholarfit-weights';
import './bulk-select';
