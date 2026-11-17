import { Controller } from '@hotwired/stimulus';
import WaveSurfer from 'wavesurfer.js';
import RecordPlugin from 'wavesurfer.js/dist/plugins/record.esm.js';
import fixWebmDuration from 'fix-webm-duration';

// The duration rule (SPEC §4): 10 s to 5 min. The server accepts ±1 s around it.
const MIN_MS = 10_000;
const WARN_MS = 270_000; // 4:30
const MAX_MS = 300_000; // 5:00

// Preferred first (SPEC §7). WebKit (Safari, and every browser on iOS) has recorded webm since
// Safari 18.4, but it can't seek in its own webm: replay stalls and so would the Report player.
// Its mp4 seeks fine, so WebKit records mp4. There is no feature test for this, hence the vendor check.
const IS_WEBKIT = navigator.vendor === 'Apple Computer, Inc.';
const MIME_TYPES = IS_WEBKIT
    ? ['audio/mp4', 'audio/webm;codecs=opus']
    : ['audio/webm;codecs=opus', 'audio/mp4'];

/*
 * Records a Speech in the browser: microphone → live waveform and timer → review → upload.
 *
 * Panels (data-recorder-target="panel", data-panel="…") show for the states they list:
 * idle, recording, review, uploading.
 */
export default class extends Controller {
    static targets = ['panel', 'waveform', 'timer', 'warning', 'message', 'reviewDuration', 'title', 'playButton'];
    static values = { uploadUrl: String, csrfToken: String };

    connect() {
        this.blob = null;
        this.durationMs = 0;
        this.show('idle');
    }

    disconnect() {
        this.wavesurfer?.destroy();
    }

    async start() {
        this.clearMessage();

        const mimeType = this.supportedMimeType();
        if (!mimeType) {
            this.showMessage('This browser cannot record audio. Please use a recent version of Chrome, Safari or Firefox.');
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            this.showMessage('Recording needs a secure page. Open Ah Counter at http://localhost or over https.');
            return;
        }

        // Ask for the microphone ourselves first: the Record plugin hides the error's name.
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            stream.getTracks().forEach((track) => track.stop());
        } catch (error) {
            this.showMessage(this.microphoneHelp(error), true);
            return;
        }

        this.createWaveform(mimeType);
        this.durationMs = 0;
        this.renderTimer(0);
        this.warningTarget.hidden = true;
        this.show('recording');

        try {
            await this.record.startRecording();
        } catch (error) {
            this.show('idle');
            this.showMessage(this.microphoneHelp(error), true);
        }
    }

    stop() {
        this.record?.stopRecording();
    }

    togglePlayback() {
        this.wavesurfer?.playPause();
    }

    discard() {
        this.wavesurfer?.stop();
        this.blob = null;
        this.titleTarget.value = '';
        this.clearMessage();
        this.show('idle');
    }

    async analyse() {
        if (!this.blob) {
            return;
        }

        this.wavesurfer?.pause();
        this.clearMessage();
        this.show('uploading');

        const extension = this.blob.type.includes('mp4') ? 'mp4' : this.blob.type.includes('ogg') ? 'ogg' : 'webm';
        const body = new FormData();
        body.append('recording', this.blob, `speech.${extension}`);
        body.append('clientDuration', (this.durationMs / 1000).toFixed(2));
        body.append('title', this.titleTarget.value);
        body.append('_token', this.csrfTokenValue);

        try {
            const response = await fetch(this.uploadUrlValue, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json().catch(() => ({}));

            if (response.ok && payload.url) {
                window.location.assign(payload.url);
                return;
            }

            this.show('review');
            this.showMessage(this.uploadError(response, payload));
        } catch {
            this.show('review');
            this.showMessage('The upload failed. Check your connection and press Analyse again.');
        }
    }

    // --- recording -----------------------------------------------------------

    createWaveform(mimeType) {
        this.wavesurfer?.destroy();
        this.wavesurfer = WaveSurfer.create({
            container: this.waveformTarget,
            height: 96,
            waveColor: '#94a3b8',
            progressColor: '#e11d48',
            cursorColor: '#e11d48',
            barWidth: 2,
            barGap: 2,
            barRadius: 2,
        });
        this.record = this.wavesurfer.registerPlugin(RecordPlugin.create({
            mimeType,
            audioBitsPerSecond: 64000,
            scrollingWaveform: true,
            renderRecordedAudio: false,
        }));

        this.record.on('record-progress', (ms) => this.onProgress(ms));
        this.record.on('record-end', (blob) => this.onRecordEnd(blob));
        this.wavesurfer.on('play', () => this.renderPlayButton(true));
        this.wavesurfer.on('pause', () => this.renderPlayButton(false));
        this.wavesurfer.on('finish', () => {
            this.renderPlayButton(false);
            this.loadForReview();
        });
    }

    onProgress(ms) {
        this.durationMs = ms;
        this.renderTimer(ms);
        this.warningTarget.hidden = ms < WARN_MS;

        if (ms >= MAX_MS) {
            this.stop();
        }
    }

    async onRecordEnd(blob) {
        // The plugin's timer is the most accurate duration we have.
        const durationMs = Math.min(this.record.getDuration() || this.durationMs, MAX_MS + 999);
        this.durationMs = durationMs;
        this.record.stopMic();

        if (durationMs < MIN_MS) {
            this.blob = null;
            this.show('idle');
            this.showMessage(`That was only ${Math.floor(durationMs / 1000)} seconds. A Speech needs at least 10 seconds — press Record to try again.`);
            return;
        }

        // Chrome's webm has no duration in its header, so <audio> can't seek in it (SPEC §7).
        this.blob = blob.type.startsWith('audio/webm')
            ? await fixWebmDuration(blob, durationMs, { logger: false })
            : blob;

        this.reviewDurationTarget.textContent = this.formatTime(durationMs);
        this.renderPlayButton(false);
        this.show('review');
        this.loadForReview();
        this.titleTarget.focus();
    }

    /*
     * Loads the Recording into the review player. Called again after each playthrough: Safari's
     * first play of a recorded blob works, but replaying the same one stops after a few seconds,
     * so every replay starts from a fresh load.
     */
    loadForReview() {
        if (!this.blob) {
            return;
        }

        // Safari can't decodeAudioData its own recording ("EncodingError: Decoding failed"). Only the
        // waveform drawing needs the decode; playback still works, so the failure is ignored.
        this.wavesurfer.loadBlob(this.blob).catch(() => {});
    }

    // --- helpers -------------------------------------------------------------

    supportedMimeType() {
        if (typeof MediaRecorder === 'undefined') {
            return null;
        }

        return MIME_TYPES.find((type) => MediaRecorder.isTypeSupported(type)) ?? null;
    }

    microphoneHelp(error) {
        const name = error?.name ?? '';
        if (name === 'NotFoundError' || name === 'OverconstrainedError') {
            return 'No microphone was found. Plug one in (or check it is switched on), then press Record again.';
        }
        if (name === 'NotReadableError') {
            return 'Your microphone is busy in another app. Close that app, then press Record again.';
        }

        return 'Ah Counter needs your microphone to record. Allow microphone access for this site: '
            + 'in Chrome, click the icon left of the address bar; in Safari, choose Safari → Settings for This Website → Microphone. '
            + 'Then press Record again.';
    }

    uploadError(response, payload) {
        const messages = (payload.violations ?? []).map((violation) => violation.title).filter(Boolean);
        if (messages.length > 0) {
            return messages.join(' ');
        }
        if (response.status === 413) {
            return 'This Recording is too large to upload. The limit is 25 MB.';
        }

        return payload.detail ?? `The upload failed (HTTP ${response.status}). Please try again.`;
    }

    show(panel) {
        this.panelTargets.forEach((element) => {
            element.hidden = !element.dataset.panel.split(' ').includes(panel);
        });
    }

    showMessage(text, isMicrophoneHelp = false) {
        this.messageTarget.textContent = text;
        this.messageTarget.dataset.kind = isMicrophoneHelp ? 'microphone' : 'error';
        this.messageTarget.hidden = false;
    }

    clearMessage() {
        this.messageTarget.textContent = '';
        this.messageTarget.hidden = true;
    }

    renderTimer(ms) {
        this.timerTarget.textContent = `${this.formatTime(ms)} / 5:00`;
    }

    renderPlayButton(playing) {
        this.playButtonTarget.textContent = playing ? 'Pause' : 'Play';
        this.playButtonTarget.setAttribute('aria-pressed', playing ? 'true' : 'false');
    }

    formatTime(ms) {
        const seconds = Math.floor(ms / 1000);

        return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    }
}
