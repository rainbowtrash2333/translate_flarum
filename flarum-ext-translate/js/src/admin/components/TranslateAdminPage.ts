import app from "flarum/admin/app";
import AdminPage from "flarum/admin/components/AdminPage";
import Button from "flarum/common/components/Button";

type Tab = "logs" | "prompt" | "backfill";

interface BackfillCounts {
	pending: number;
	running: number;
	done: number;
	error: number;
}

interface BackfillResult {
	scanned: number;
	inserted: number;
}

interface LogEntry {
	id: number;
	created_at: string;
	post_id: number;
	discussion_id?: number;
	post_number?: number;
	target_lang: string;
	status: string;
	tokens_in?: number;
	tokens_out?: number;
	latency_ms?: number;
	prompt_full: string;
	response_final: string;
	source_content: string;
	translated_content: string;
	error?: string;
}

interface LogsResponse {
	data?: LogEntry[];
	total?: number;
	page?: number;
	perPage?: number;
	totalPages?: number;
}

export default class TranslateAdminPage extends AdminPage {
	activeTab: Tab = "logs";
	logsPage = 1;
	logsFilterLang = "";
	logsFilterStatus = "";
	logsData: LogsResponse | null = null;
	logsLoading = false;
	promptValue = "";
	promptLangMap: Record<string, string> = {};
	backfillLang = "";
	backfillLoading = false;
	backfillPolling: number | null = null;
	backfillResult: BackfillResult | null = null;
	backfillCounts: BackfillCounts | null = null;
	expandedLog: number | null = null;

	oninit(_vnode: unknown) {
		super.oninit(_vnode);
		this.loadPrompt();
		this.loadLogs();
	}

	onremove(_vnode: unknown) {
		super.onremove(_vnode);
		this.stopBackfillPolling();
	}

	headerInfo() {
		return {
			className: "TranslateAdminPage",
			icon: "fas fa-language",
			title: app.translator.trans("twikura-translate.admin.page.title"),
			description: app.translator.trans(
				"twikura-translate.admin.page.description",
			),
		};
	}

	header(vnode: unknown) {
		return [super.header(vnode), this.tabBar()];
	}

	content(_vnode: unknown) {
		return m(".TranslateAdminPage-content", [
			this.activeTab === "logs" ? this.logsPanel() : null,
			this.activeTab === "prompt" ? this.promptPanel() : null,
			this.activeTab === "backfill" ? this.backfillPanel() : null,
		]);
	}

	tabBar() {
		return m(".TranslateAdminPage-tabs.container", [
			this.tabButton(
				"logs",
				app.translator.trans("twikura-translate.admin.tabs.logs"),
			),
			this.tabButton(
				"prompt",
				app.translator.trans("twikura-translate.admin.tabs.prompt"),
			),
			this.tabButton(
				"backfill",
				app.translator.trans("twikura-translate.admin.tabs.backfill"),
			),
		]);
	}

	tabButton(tab: Tab, label: string) {
		return m(
			`button.Button.TwikuraTranslateAdminTab${this.activeTab === tab ? ".active" : ""}`,
			{
				onclick: () => {
					this.activeTab = tab;
					if (tab === "logs") this.loadLogs();
					if (tab === "prompt") this.loadPrompt();
					m.redraw();
				},
			},
			label,
		);
	}

	loadLogs() {
		this.logsLoading = true;
		app
			.request<LogsResponse>({
				method: "GET",
				url: apiUrl("/translate-logs"),
				params: {
					page: this.logsPage,
					lang: this.logsFilterLang || undefined,
					status: this.logsFilterStatus || undefined,
				},
			})
			.then((data) => {
				this.logsData = data;
				this.logsLoading = false;
				m.redraw();
			})
			.catch(() => {
				this.logsLoading = false;
				m.redraw();
			});
	}

	loadPrompt() {
		this.promptValue = getSetting("twikura-translate.system_prompt");
		this.promptLangMap = parseLangMap(getSetting("twikura-translate.lang_map"));
		if (!this.backfillLang && Object.keys(this.promptLangMap).length > 0) {
			this.backfillLang = Object.keys(this.promptLangMap)[0];
		}
		m.redraw();
	}

	logsPanel() {
		if (this.logsLoading && !this.logsData) {
			return m(
				".TwikuraTranslateLoading",
				app.translator.trans("twikura-translate.admin.loading"),
			);
		}

		const logs = this.logsData?.data || [];
		const totalPages = this.logsData?.totalPages ?? this.logsPage;

		return m(".TwikuraTranslateLogs", [
			m("h3", app.translator.trans("twikura-translate.admin.logs.title")),
			m(".Form-group", [
				m(
					"label",
					app.translator.trans("twikura-translate.admin.logs.filter_lang"),
				),
				m(
					"select.FormControl",
					{
						value: this.logsFilterLang,
						onchange: (e: Event) => {
							this.logsFilterLang = (e.target as HTMLSelectElement).value;
							this.logsPage = 1;
							this.loadLogs();
						},
					},
					[
						m(
							"option",
							{ value: "" },
							app.translator.trans("twikura-translate.admin.logs.all_lang"),
						),
						...Object.entries(this.promptLangMap).map(([code, name]) =>
							m("option", { value: code }, `${code} - ${name}`),
						),
					],
				),
			]),
			m(".Form-group", [
				m(
					"label",
					app.translator.trans("twikura-translate.admin.logs.filter_status"),
				),
				m(
					"select.FormControl",
					{
						value: this.logsFilterStatus,
						onchange: (e: Event) => {
							this.logsFilterStatus = (e.target as HTMLSelectElement).value;
							this.logsPage = 1;
							this.loadLogs();
						},
					},
					[
						m(
							"option",
							{ value: "" },
							app.translator.trans("twikura-translate.admin.logs.all_status"),
						),
						m("option", { value: "done" }, "done"),
						m("option", { value: "error" }, "error"),
					],
				),
			]),
			this.renderLogsTable(logs),
			this.renderPagination(totalPages),
		]);
	}

	renderLogsTable(logs: LogEntry[]) {
		if (!logs.length) {
			return m(
				".Alert",
				app.translator.trans("twikura-translate.admin.logs.empty"),
			);
		}

		return m("table.Table", [
			m("thead", [
				m("tr", [
					m(
						"th",
						app.translator.trans("twikura-translate.admin.logs.created_at"),
					),
					m("th", app.translator.trans("twikura-translate.admin.logs.post")),
					m("th", app.translator.trans("twikura-translate.admin.logs.lang")),
					m("th", app.translator.trans("twikura-translate.admin.logs.status")),
					m("th", app.translator.trans("twikura-translate.admin.logs.tokens")),
					m("th", app.translator.trans("twikura-translate.admin.logs.latency")),
				]),
			]),
			m(
				"tbody",
				logs.map((log) => this.renderLogRow(log)),
			),
		]);
	}

	renderLogRow(log: LogEntry) {
		const isExpanded = this.expandedLog === log.id;

		return [
			m(
				"tr",
				{
					key: log.id,
					onclick: () => {
						this.expandedLog = isExpanded ? null : log.id;
						m.redraw();
					},
				},
				[
					m("td", log.created_at),
					m(
						"td",
						m("a", { href: postUrl(log), target: "_blank" }, `#${log.post_id}`),
					),
					m("td", log.target_lang),
					m("td", log.status),
					m("td", `${log.tokens_in ?? "-"}/${log.tokens_out ?? "-"}`),
					m("td", `${log.latency_ms ?? "-"}ms`),
				],
			),
			isExpanded
				? m(
						"tr",
						{ key: `expanded-${log.id}` },
						m("td", { colspan: 6 }, [
							m(
								"h4",
								app.translator.trans(
									"twikura-translate.admin.logs.prompt_full",
								),
							),
							m("pre", log.prompt_full),
							m(
								"h4",
								app.translator.trans(
									"twikura-translate.admin.logs.response_final",
								),
							),
							m("pre", log.response_final),
							m(
								"h4",
								app.translator.trans(
									"twikura-translate.admin.logs.source_content",
								),
							),
							m("pre", log.source_content),
							m(
								"h4",
								app.translator.trans(
									"twikura-translate.admin.logs.translated_content",
								),
							),
							m("pre", log.translated_content),
							log.error ? m(".Alert.Alert--error", log.error) : null,
						]),
					)
				: null,
		];
	}

	renderPagination(totalPages: number) {
		return m(".TwikuraTranslatePagination", [
			m(
				Button,
				{
					className: "Button",
					disabled: this.logsPage <= 1,
					onclick: () => {
						this.logsPage--;
						this.loadLogs();
					},
				},
				app.translator.trans("twikura-translate.admin.logs.prev"),
			),
			m("span", `Page ${this.logsPage}`),
			m(
				Button,
				{
					className: "Button",
					disabled: this.logsPage >= totalPages,
					onclick: () => {
						this.logsPage++;
						this.loadLogs();
					},
				},
				app.translator.trans("twikura-translate.admin.logs.next"),
			),
		]);
	}

	promptPanel() {
		return m(".TwikuraTranslatePrompt", [
			m("h3", app.translator.trans("twikura-translate.admin.prompt.title")),
			m(
				".helpText",
				app.translator.trans("twikura-translate.admin.prompt.description"),
			),
			m("textarea.FormControl", {
				rows: 12,
				readonly: true,
				value: this.promptValue,
			}),
			m("h4", app.translator.trans("twikura-translate.admin.prompt.lang_map")),
			m(
				"ul",
				Object.entries(this.promptLangMap).map(([code, name]) =>
					m("li", `${code} → ${name}`),
				),
			),
		]);
	}

	backfillPanel() {
		const langOptions = Object.entries(this.promptLangMap).map(([code, name]) =>
			m("option", { value: code }, `${code} - ${name}`),
		);
		const counts = this.backfillCounts;
		const total = counts
			? counts.pending + counts.running + counts.done + counts.error
			: 0;
		const finished = counts ? counts.done + counts.error : 0;
		const progress = total > 0 ? (finished / total) * 100 : 0;

		return m(".TwikuraTranslateBackfill", [
			m("h3", app.translator.trans("twikura-translate.admin.backfill.title")),
			m(".Form-group", [
				m(
					"label",
					app.translator.trans("twikura-translate.admin.backfill.lang"),
				),
				m(
					"select.FormControl",
					{
						value: this.backfillLang,
						onchange: (e: Event) => {
							this.backfillLang = (e.target as HTMLSelectElement).value;
							m.redraw();
						},
					},
					langOptions,
				),
			]),
			m(
				Button,
				{
					className: "Button Button--primary",
					loading: this.backfillLoading,
					disabled: this.backfillLoading || !this.backfillLang,
					onclick: () => this.runBackfill(),
				},
				app.translator.trans("twikura-translate.admin.backfill.scan"),
			),
			this.backfillResult
				? m(
						".TwikuraTranslateBackfillResult",
						app.translator.trans("twikura-translate.admin.backfill.result", {
							scanned: String(this.backfillResult.scanned),
							inserted: String(this.backfillResult.inserted),
						}),
					)
				: null,
			counts
				? m(".TwikuraTranslateProgress", [
						m(".TwikuraTranslateProgress-bar", {
							style: { width: `${progress}%` },
						}),
						m(".TwikuraTranslateProgress-label", `${finished}/${total}`),
					])
				: null,
		]);
	}

	runBackfill() {
		if (!this.backfillLang) return;
		this.backfillLoading = true;
		app
			.request<BackfillResult>({
				method: "POST",
				url: apiUrl("/translate/backfill"),
				body: { lang: this.backfillLang },
			})
			.then((result) => {
				this.backfillResult = result;
				this.backfillLoading = false;
				this.startBackfillPolling();
				m.redraw();
			})
			.catch(() => {
				this.backfillLoading = false;
				m.redraw();
			});
	}

	startBackfillPolling() {
		if (this.backfillPolling) return;
		this.backfillPolling = window.setInterval(() => {
			app
				.request<BackfillCounts>({
					method: "GET",
					url: apiUrl("/translate/backfill/status"),
					params: { lang: this.backfillLang },
				})
				.then((data) => {
					this.backfillCounts = {
						pending: data.pending || 0,
						running: data.running || 0,
						done: data.done || 0,
						error: data.error || 0,
					};
					m.redraw();
					if (this.backfillCounts.pending + this.backfillCounts.running === 0) {
						this.stopBackfillPolling();
					}
				})
				.catch(() => this.stopBackfillPolling());
		}, 5000);
	}

	stopBackfillPolling() {
		if (this.backfillPolling) {
			clearInterval(this.backfillPolling);
			this.backfillPolling = null;
		}
	}
}

function apiUrl(path: string): string {
	const base = app.forum.attribute<string>("apiUrl") || "/api";
	return base.replace(/\/$/, "") + path;
}

function getSetting(key: string): string {
	return app.data.settings?.[key] || "";
}

function parseLangMap(value: string): Record<string, string> {
	const map: Record<string, string> = {};
	if (!value) return map;
	value.split("\n").forEach((line) => {
		const idx = line.indexOf(":");
		if (idx <= 0) return;
		const code = line.slice(0, idx).trim();
		const name = line.slice(idx + 1).trim();
		if (code && name) map[code] = name;
	});
	return map;
}

function postUrl(log: LogEntry): string {
	if (log.discussion_id && log.post_number)
		return `/d/${log.discussion_id}/${log.post_number}`;
	if (log.discussion_id) return `/d/${log.discussion_id}`;
	return "#";
}
