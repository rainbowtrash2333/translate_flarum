import Component from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import { extend } from "flarum/common/extend";
import type Model from "flarum/common/Model";
import type ItemList from "flarum/common/utils/ItemList";
import app from "flarum/forum/app";
import CommentPost from "flarum/forum/components/CommentPost";
import HeaderSecondary from "flarum/forum/components/HeaderSecondary";
import Post from "flarum/forum/components/Post";

const EXT_ID = "twikura-translate";
const AUTO_KEY = "twikuraTranslateAutoEnabled";

interface PostState {
	showTranslation: boolean;
	autoAttempted: boolean;
}

const states = new Map<string, PostState>();
let pollTimer: number | null = null;

function autoEnabled(): boolean {
	const local = localStorage.getItem(AUTO_KEY);
	if (local !== null) return local === "1";
	return app.forum.attribute<boolean>("twikuraTranslateAutoEnabled") === true;
}

function setAutoEnabled(enabled: boolean): void {
	localStorage.setItem(AUTO_KEY, enabled ? "1" : "0");
	states.forEach((state) => {
		state.showTranslation = enabled;
	});
	if (enabled) ensurePolling();
	m.redraw();
}

function currentLang(): string {
	return (
		document.documentElement.getAttribute("lang") || app.data.locale || "en"
	);
}

function isTranslatablePost(post: Model): boolean {
	return (
		post.contentType() === "comment" && typeof post.contentHtml() === "string"
	);
}

function getPostState(post: Model): PostState {
	const id = post.id();
	let state = states.get(id);
	if (!state) {
		state = { showTranslation: autoEnabled(), autoAttempted: false };
		states.set(id, state);
	}
	return state;
}

function getPostStatus(post: Model): string | undefined {
	return post.attribute<string>("translation_status");
}

function getTranslatedContent(post: Model): string | undefined {
	return post.attribute<string>("translated_content");
}

function getTranslationTargetLang(post: Model): string | undefined {
	return post.attribute<string>("translation_target_lang");
}

function apiUrl(path: string): string {
	const base = app.forum.attribute<string>("apiUrl") || "/api";
	return base.replace(/\/$/, "") + path;
}

function canRetry(): boolean {
	return (
		!!app.session.user ||
		app.forum.attribute<boolean>("twikuraTranslateAllowGuests") === true
	);
}

function getCurrentDiscussion(): Model | undefined {
	const current = app.current as { discussion?: Model } | undefined;
	return current?.discussion;
}

function hasPendingPost(): boolean {
	const discussion = getCurrentDiscussion();
	if (!discussion) return false;
	const posts = discussion.posts();
	if (!Array.isArray(posts)) return false;
	return posts.some((post) => {
		const status = getPostStatus(post);
		return status === "pending" || status === "running";
	});
}

function ensurePolling(): void {
	if (pollTimer) return;
	pollTimer = window.setInterval(() => {
		if (!hasPendingPost()) {
			stopPolling();
			return;
		}
		const discussion = getCurrentDiscussion();
		if (discussion) {
			app.store.find("discussions", discussion.id());
		}
	}, 5000);
}

function stopPolling(): void {
	if (pollTimer) {
		clearInterval(pollTimer);
		pollTimer = null;
	}
}

function updatePolling(): void {
	if (hasPendingPost()) ensurePolling();
}

function retryTranslation(post: Model): void {
	if (!canRetry()) return;

	app
		.request({
			method: "POST",
			url: apiUrl("/translate/retry"),
			body: {
				post_id: post.id(),
				target_lang: currentLang(),
			},
		})
		.then(() => app.store.find("posts", post.id()))
		.then(() => {
			ensurePolling();
			m.redraw();
		})
		.catch((error: Error) => {
			alert(
				error.message ||
					app.translator.trans("twikura-translate.forum.translation_failed"),
			);
		});
}

class TranslatedPostBody extends Component<{ post: Model }> {
	oncreate() {
		updatePolling();
	}

	onupdate() {
		updatePolling();
	}

	view() {
		const post = this.attrs.post;
		const state = getPostState(post);
		const status = getPostStatus(post);
		const translatedContent = getTranslatedContent(post);
		const targetLang = getTranslationTargetLang(post);
		const current = currentLang();
		const isCurrentLang = targetLang === current;

		if (
			state.showTranslation &&
			status === "done" &&
			isCurrentLang &&
			translatedContent
		) {
			return m.trust(translatedContent);
		}

		const original = m.trust(post.contentHtml() || "");

		if (status === "pending" || status === "running") {
			return m(".TwikuraTranslateBody", [
				original,
				m(".TwikuraTranslateStatus.TwikuraTranslateStatus--pending", [
					m("i.fas.fa-spinner.fa-spin"),
					" ",
					app.translator.trans("twikura-translate.forum.translating"),
				]),
			]);
		}

		if (status === "error") {
			return m(".TwikuraTranslateBody", [
				original,
				m(".TwikuraTranslateStatus.TwikuraTranslateStatus--error", [
					app.translator.trans("twikura-translate.forum.translation_failed"),
					canRetry()
						? m(
								Button,
								{
									className: "Button Button--link TwikuraTranslateRetryButton",
									icon: "fas fa-redo",
									onclick: () => retryTranslation(post),
								},
								app.translator.trans("twikura-translate.forum.retry"),
							)
						: null,
				]),
			]);
		}

		return original;
	}
}

function addAutoButton(): void {
	extend(HeaderSecondary.prototype, "items", (items: ItemList) => {
		items.add(
			"twikuraTranslateAuto",
			m(
				Button,
				{
					className: `Button Button--link TwikuraTranslateAutoButton${autoEnabled() ? " active" : ""}`,
					icon: "fas fa-language",
					onclick: () => setAutoEnabled(!autoEnabled()),
					"aria-pressed": autoEnabled() ? "true" : "false",
				},
				autoEnabled()
					? app.translator.trans("twikura-translate.forum.disable_translate")
					: app.translator.trans("twikura-translate.forum.auto_translate"),
			),
			25,
		);
	});
}

function addPostButton(): void {
	extend(Post.prototype, "footerItems", function (this: Post, items: ItemList) {
		const post = this.attrs.post;
		if (!isTranslatablePost(post)) return;

		const state = getPostState(post);
		const status = getPostStatus(post);
		const targetLang = getTranslationTargetLang(post);
		const current = currentLang();
		const isCurrentLang = targetLang === current;
		const done = status === "done" && isCurrentLang;

		let label: string;
		let loading = false;
		let disabled = false;
		let onClick: () => void;

		if (done) {
			label = state.showTranslation
				? app.translator.trans("twikura-translate.forum.original")
				: app.translator.trans("twikura-translate.forum.translate");
			onClick = () => {
				state.showTranslation = !state.showTranslation;
				m.redraw();
			};
		} else if (status === "error") {
			label = app.translator.trans("twikura-translate.forum.retry");
			onClick = () => retryTranslation(post);
			if (!canRetry()) disabled = true;
		} else if (status === "pending" || status === "running") {
			label = app.translator.trans("twikura-translate.forum.translating");
			loading = true;
			disabled = true;
			onClick = () => {};
		} else {
			label = app.translator.trans("twikura-translate.forum.translate");
			disabled = true;
			onClick = () => {};
		}

		items.add(
			"twikuraTranslate",
			m(
				Button,
				{
					className: "Button Button--link TwikuraTranslatePostButton",
					loading: loading,
					disabled: disabled,
					onclick: onClick,
				},
				label,
			),
			-10,
		);
	});
}

function replacePostBody(): void {
	extend(
		CommentPost.prototype,
		"bodyItems",
		function (this: CommentPost, items: ItemList) {
			const post = this.attrs.post;
			if (!isTranslatablePost(post)) return;

			if (typeof items.replace === "function") {
				items.replace("content", m(TranslatedPostBody, { post: post }), 100);
			} else {
				items.remove("content");
				items.add("content", m(TranslatedPostBody, { post: post }), 100);
			}
		},
	);
}

app.initializers.add(EXT_ID, () => {
	addAutoButton();
	addPostButton();
	replacePostBody();
});
