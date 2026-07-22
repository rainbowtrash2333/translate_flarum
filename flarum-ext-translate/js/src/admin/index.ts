import app from "flarum/admin/app";
import AdminNav from "flarum/admin/components/AdminNav";
import Button from "flarum/common/components/Button";
import LinkButton from "flarum/common/components/LinkButton";
import { extend } from "flarum/common/extend";
import type ItemList from "flarum/common/utils/ItemList";
import TranslateAdminPage from "./components/TranslateAdminPage";

const EXT_ID = "twikura-translate-admin";

let testText = "hello";
let testResult: string | null = null;
let testError: string | null = null;
let testing = false;

function currentLang(): string {
	return (
		document.documentElement.getAttribute("lang") || app.data.locale || "en"
	);
}

function apiUrl(path: string): string {
	const base = app.forum.attribute<string>("apiUrl") || "/api";
	return base.replace(/\/$/, "") + path;
}

interface TranslationError {
	message?: string;
	response?: {
		errors?: Array<{ detail?: string }>;
	};
}

function runTranslationTest(): void {
	testing = true;
	testResult = null;
	testError = null;
	m.redraw();

	app
		.request<{ translated?: string }>({
			method: "POST",
			url: apiUrl("/translate/test"),
			body: {
				lang: currentLang(),
				text: testText || "",
			},
		})
		.then((response) => {
			testResult = response.translated
				? response.translated
				: JSON.stringify(response);
		})
		.catch((error: TranslationError) => {
			if (error.response?.errors?.[0]) {
				testError =
					error.response.errors[0].detail ||
					app.translator.trans("twikura-translate.admin.test.failed");
			} else {
				testError =
					error.message ||
					app.translator.trans("twikura-translate.admin.test.failed");
			}
		})
		.then(() => {
			testing = false;
			m.redraw();
		});
}

function testPanel(): unknown {
	return m(".Form-group.TwikuraTranslateTestPanel", [
		m("label", app.translator.trans("twikura-translate.admin.test.title")),
		m(
			".helpText",
			app.translator.trans("twikura-translate.admin.test.description", {
				lang: currentLang(),
			}),
		),
		m("input.FormControl", {
			type: "text",
			value: testText,
			oninput: (event: InputEvent) => {
				testText = (event.target as HTMLInputElement).value;
			},
		}),
		m(
			Button,
			{
				className: "Button Button--primary TwikuraTranslateTestButton",
				loading: testing,
				disabled: testing,
				onclick: runTranslationTest,
			},
			app.translator.trans("twikura-translate.admin.test.button"),
		),
		testResult ? m("pre.TwikuraTranslateTestResult", testResult) : null,
		testError
			? m(".Alert.Alert--error.TwikuraTranslateTestError", testError)
			: null,
	]);
}

function registerSettings(): void {
	app.extensionData
		.for("twikura-translate")
		.registerSetting({
			setting: "twikura-translate.auto_translate",
			label: app.translator.trans(
				"twikura-translate.admin.settings.auto_translate",
			),
			type: "boolean",
		})
		.registerSetting({
			setting: "twikura-translate.llm_base_url",
			label: app.translator.trans(
				"twikura-translate.admin.settings.llm_base_url",
			),
			type: "text",
		})
		.registerSetting({
			setting: "twikura-translate.llm_api_key",
			label: app.translator.trans(
				"twikura-translate.admin.settings.llm_api_key",
			),
			type: "text",
		})
		.registerSetting({
			setting: "twikura-translate.llm_model",
			label: app.translator.trans("twikura-translate.admin.settings.llm_model"),
			type: "text",
		})
		.registerSetting({
			setting: "twikura-translate.llm_timeout",
			label: app.translator.trans(
				"twikura-translate.admin.settings.llm_timeout",
			),
			type: "number",
		})
		.registerSetting({
			setting: "twikura-translate.llm_max_retries",
			label: app.translator.trans(
				"twikura-translate.admin.settings.llm_max_retries",
			),
			type: "number",
		})
		.registerSetting({
			setting: "twikura-translate.lang_map",
			label: app.translator.trans("twikura-translate.admin.settings.lang_map"),
			type: "textarea",
		})
		.registerSetting({
			setting: "twikura-translate.system_prompt",
			label: app.translator.trans(
				"twikura-translate.admin.settings.system_prompt",
			),
			type: "textarea",
		})
		.registerSetting({
			setting: "twikura-translate.allow_guests",
			label: app.translator.trans(
				"twikura-translate.admin.settings.allow_guests",
			),
			type: "boolean",
		})
		.registerSetting(testPanel);
}

function registerRoute(): void {
	app.routes["twikura-translate"] = {
		path: "/translate",
		component: TranslateAdminPage,
	};
}

function registerNav(): void {
	extend(AdminNav.prototype, "items", (items: ItemList) => {
		items.add(
			"twikura-translate",
			m(
				LinkButton,
				{
					href: app.route("twikura-translate"),
					icon: "fas fa-language",
					title: app.translator.trans("twikura-translate.admin.nav.translate"),
				},
				app.translator.trans("twikura-translate.admin.nav.translate"),
			),
			45,
		);
	});
}

app.initializers.add(EXT_ID, () => {
	registerSettings();
	registerRoute();
	registerNav();
});
