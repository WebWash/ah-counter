import { Controller } from '@hotwired/stimulus';
import WaveSurfer from 'wavesurfer.js';
import RegionsPlugin from 'wavesurfer.js/dist/plugins/regions.esm.js';

// A Filler's marker on the waveform: an ink stem with a small highlighter tab on top, marked the
// same way as the Transcript (solid for a Filler Sound, underlined for a Filler Phrase), so the
// kind never depends on colour. WaveSurfer draws regions in a shadow root, hence the inline styles.
const MARK_FILLS = {
    sound: '#ffe066',
    phrase: 'linear-gradient(#fff 50%, #ffe066 50%)',
};

// Below this width (the phone Play bar) the tabs would run into each other, so a Filler is a
// plain stem; its kind is still in the Transcript and the tallies. The same goes for a Filler
// closer than one tab to the one before it.
const COMPACT_WAVEFORM_PX = 240;
const TAB_PX = 10;

function waveformMark(kind, compact) {
    const stem = document.createElement('div');
    stem.style.cssText = 'position:absolute;inset:0;border-left:2px solid #15181d;pointer-events:none';
    if (compact) {
        return stem;
    }
    const tab = document.createElement('div');
    tab.style.cssText = `position:absolute;left:-2px;top:0;width:${TAB_PX}px;height:${TAB_PX}px;box-sizing:border-box;border:2px solid #15181d;border-radius:2px;background:${MARK_FILLS[kind] ?? MARK_FILLS.sound}`;
    stem.append(tab);

    return stem;
}

const PLAY_ICON = '<svg class="icon" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M6.5 4.5v11l9-5.5z" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
const PAUSE_ICON = '<svg class="icon" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M7 4.5v11M13 4.5v11" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';

// A Filler list row stays lit for this long after its Filler ends.
const ROW_LINGER_SECONDS = 1;

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
    static targets = ['audio', 'waveform', 'playButton', 'playIcon', 'playLabel', 'time', 'segment', 'waveformError', 'row'];
    static values = { fillers: Array };

    connect() {
        this.currentSegment = null;
        this.currentRow = null;
        this.segmentStarts = this.segmentTargets.map((segment) => parseFloat(segment.dataset.start));

        this.regions = RegionsPlugin.create();
        this.wavesurfer = WaveSurfer.create({
            container: this.waveformTarget,
            media: this.audioTarget,
            height: 56,
            waveColor: '#b8c6e0',
            progressColor: '#1f4fa0',
            cursorColor: '#15181d',
            cursorWidth: 2,
            barWidth: 3,
            barGap: 2,
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
        const width = this.waveformTarget.clientWidth;
        const compact = width < COMPACT_WAVEFORM_PX;
        const pxPerSecond = width / (this.wavesurfer.getDuration() || 1);
        let lastTabAt = -Infinity;
        this.fillersValue.forEach((filler) => {
            const x = filler.start * pxPerSecond;
            const crowded = x - lastTabAt < TAB_PX + 2;
            if (!compact && !crowded) {
                lastTabAt = x;
            }
            this.regions.addRegion({
                start: filler.start,
                // Very short Fillers still get a region wide enough to see and click.
                end: Math.max(filler.end, filler.start + 0.15),
                color: 'transparent',
                content: waveformMark(filler.kind, compact || crowded),
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
        this.playLabelTarget.textContent = playing ? 'Pause' : 'Play';
        this.playIconTarget.innerHTML = playing ? PAUSE_ICON : PLAY_ICON;
        this.playButtonTarget.setAttribute('aria-pressed', playing ? 'true' : 'false');
    }

    renderTime() {
        const current = this.audioTarget.currentTime || 0;
        const duration = Number.isFinite(this.audioTarget.duration) ? this.audioTarget.duration : 0;
        this.timeTarget.textContent = `${this.formatTime(current)} / ${this.formatTime(duration)}`;

        const started = current > 0 || !this.audioTarget.paused;
        // Every seek starts LEAD_IN_SECONDS early, so look that far ahead: the word that was
        // pressed lights at once instead of the word before it.
        this.highlightSegmentAt(started ? current + LEAD_IN_SECONDS : -1);
        this.highlightRowAt(started ? current : -1);
    }

    // Lights the Filler list row of the Filler being played, and for a moment after it.
    highlightRowAt(seconds) {
        const row = seconds < 0 ? null : this.rowTargets.findLast((candidate) => {
            const start = parseFloat(candidate.dataset.start) - LEAD_IN_SECONDS;
            const end = parseFloat(candidate.dataset.end) + ROW_LINGER_SECONDS;

            return start <= seconds && seconds < end;
        }) ?? null;
        if (row === this.currentRow) {
            return;
        }
        this.currentRow?.removeAttribute('data-current');
        row?.setAttribute('data-current', '');
        this.currentRow = row;
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

        const segment = found >= 0 ? this.segmentTargets[found] : null;
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
