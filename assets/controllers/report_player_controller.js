import { Controller } from '@hotwired/stimulus';
import WaveSurfer from 'wavesurfer.js';
import RegionsPlugin from 'wavesurfer.js/dist/plugins/regions.esm.js';

// Region colours by kind: Filler Sounds rose, Filler Phrases amber (matches the transcript).
const REGION_COLORS = {
    sound: 'rgba(225, 29, 72, 0.28)',
    phrase: 'rgba(217, 119, 6, 0.28)',
};

// Start playback a moment before a Filler so its onset isn't clipped. Kept well inside the
// ticket's "within about 0.3 s" so browser seek error has room too.
const LEAD_IN_SECONDS = 0.1;

/*
 * The Report's player (SPEC §7): a waveform with one region per counted Filler, and seeking from
 * the transcript, the regions and the Filler list.
 *
 * The <audio> element (data-report-player-target="audio") is the source of truth for playback:
 * the Recording is seekable (ticket 07) and served with HTTP Range. WaveSurfer only draws the
 * waveform and regions over it. If the browser can't decode the Recording for the waveform
 * (Safari has refused some of its own recordings), every seek and play still works.
 */
export default class extends Controller {
    static targets = ['audio', 'waveform', 'playButton', 'time', 'segment', 'waveformError'];
    static values = { fillers: Array };

    connect() {
        this.currentSegment = null;
        this.segmentStarts = this.segmentTargets.map((segment) => parseFloat(segment.dataset.start));

        this.regions = RegionsPlugin.create();
        this.wavesurfer = WaveSurfer.create({
            container: this.waveformTarget,
            media: this.audioTarget,
            height: 96,
            waveColor: '#94a3b8',
            progressColor: '#475569',
            cursorColor: '#0f172a',
            barWidth: 2,
            barGap: 1,
            barRadius: 2,
            normalize: true,
            dragToSeek: true,
            plugins: [this.regions],
        });

        this.wavesurfer.on('ready', () => this.addRegions());
        this.wavesurfer.on('error', () => this.showWaveformError());
        this.regions.on('region-clicked', (region, event) => {
            event.stopPropagation();
            this.playFrom(Math.max(0, region.start - LEAD_IN_SECONDS));
        });

        this.onPlay = () => this.renderPlayButton(true);
        this.onPause = () => this.renderPlayButton(false);
        this.onTimeUpdate = () => this.renderTime();
        this.audioTarget.addEventListener('play', this.onPlay);
        this.audioTarget.addEventListener('pause', this.onPause);
        this.audioTarget.addEventListener('ended', this.onPause);
        this.audioTarget.addEventListener('timeupdate', this.onTimeUpdate);
        this.audioTarget.addEventListener('loadedmetadata', this.onTimeUpdate);
        this.renderTime();
    }

    disconnect() {
        this.audioTarget.removeEventListener('play', this.onPlay);
        this.audioTarget.removeEventListener('pause', this.onPause);
        this.audioTarget.removeEventListener('ended', this.onPause);
        this.audioTarget.removeEventListener('timeupdate', this.onTimeUpdate);
        this.audioTarget.removeEventListener('loadedmetadata', this.onTimeUpdate);
        this.wavesurfer?.destroy();
    }

    toggle() {
        if (this.audioTarget.paused) {
            this.audioTarget.play().catch(() => {});
        } else {
            this.audioTarget.pause();
        }
    }

    // Transcript words, Filler list rows: data-start holds the time in seconds.
    seek(event) {
        const start = parseFloat(event.currentTarget.dataset.start);
        if (!Number.isNaN(start)) {
            this.playFrom(Math.max(0, start - LEAD_IN_SECONDS));
        }
    }

    playFrom(seconds) {
        this.audioTarget.currentTime = seconds;
        this.audioTarget.play().catch(() => {});
    }

    addRegions() {
        this.regions.clearRegions();
        this.fillersValue.forEach((filler) => {
            this.regions.addRegion({
                start: filler.start,
                // Very short Fillers still get a region wide enough to see and click.
                end: Math.max(filler.end, filler.start + 0.15),
                color: REGION_COLORS[filler.kind] ?? REGION_COLORS.sound,
                drag: false,
                resize: false,
            });
        });
    }

    showWaveformError() {
        if (this.hasWaveformErrorTarget) {
            this.waveformErrorTarget.hidden = false;
        }
    }

    renderPlayButton(playing) {
        this.playButtonTarget.textContent = playing ? 'Pause' : 'Play';
        this.playButtonTarget.setAttribute('aria-pressed', playing ? 'true' : 'false');
    }

    renderTime() {
        const current = this.audioTarget.currentTime || 0;
        const duration = Number.isFinite(this.audioTarget.duration) ? this.audioTarget.duration : 0;
        this.timeTarget.textContent = `${this.formatTime(current)} / ${this.formatTime(duration)}`;
        this.highlightSegmentAt(current);
    }

    // Highlights the transcript segment being spoken (the last one starting at or before now).
    highlightSegmentAt(seconds) {
        let low = 0;
        let high = this.segmentStarts.length - 1;
        let found = -1;
        while (low <= high) {
            const middle = (low + high) >> 1;
            if (this.segmentStarts[middle] <= seconds) {
                found = middle;
                low = middle + 1;
            } else {
                high = middle - 1;
            }
        }

        const segment = found >= 0 && (seconds > 0 || !this.audioTarget.paused) ? this.segmentTargets[found] : null;
        if (segment === this.currentSegment) {
            return;
        }
        this.currentSegment?.removeAttribute('data-current');
        segment?.setAttribute('data-current', '');
        this.currentSegment = segment;
    }

    formatTime(seconds) {
        const whole = Math.floor(seconds);

        return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
    }
}
