import 'package:flutter/material.dart';

import '../../models/models.dart';
import '../../state/jobs_controller.dart';
import '../../state/outbox_controller.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/consent_notice.dart';
import 'claim_outcome_screen.dart';
import 'submit_claim_screen.dart';

class JobDetailScreen extends StatefulWidget {
  const JobDetailScreen({
    super.key,
    required this.job,
    required this.jobs,
    required this.outbox,
  });

  final WorkOrder job;
  final JobsController jobs;
  final OutboxController outbox;

  @override
  State<JobDetailScreen> createState() => _JobDetailScreenState();
}

class _JobDetailScreenState extends State<JobDetailScreen> {
  late WorkOrder _job = widget.job;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    final fresh = await widget.jobs.load(widget.job.id);
    if (fresh != null && mounted) setState(() => _job = fresh);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_job.reference)),
      body: SafeArea(
        child: ListenableBuilder(
          listenable: widget.outbox,
          builder: (BuildContext context, _) {
            final claim = widget.jobs.claimFor(_job.reference) ??
                widget.outbox.submitted[_job.id];
            final queued = widget.outbox.hasPendingFor(_job.id);
            final canSubmit = _job.acceptsClaim && claim == null && !queued;

            return ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
              children: <Widget>[
                Text(
                  _job.customer ?? '—',
                  style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 4),
                Text(
                  _job.serviceType ?? '—',
                  style: const TextStyle(fontSize: 15, color: SpColors.ink2),
                ),
                const SizedBox(height: 20),

                SpPanel(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      const Eyebrow('Where'),
                      const SizedBox(height: 10),
                      Text(
                        _job.site.name ?? '—',
                        style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
                      ),
                      if (_job.site.address != null) ...<Widget>[
                        const SizedBox(height: 4),
                        Text(
                          _job.site.address!,
                          style: const TextStyle(fontSize: 15, color: SpColors.ink2),
                        ),
                      ],
                      const SizedBox(height: 12),
                      const Divider(),
                      InfoRow('Site area', '${_job.site.radiusM ?? "—"} m radius', mono: true),
                      InfoRow('Scheduled', formatWhen(_job.scheduledAt), mono: true),
                      InfoRow(
                        'Window',
                        '${formatWhen(_job.windowStartsAt)} — ${formatWhen(_job.windowEndsAt)}',
                        mono: true,
                      ),
                      InfoRow('Work phone', _job.deviceReference ?? 'not linked', mono: true),
                    ],
                  ),
                ),

                if (_job.description != null) ...<Widget>[
                  const SizedBox(height: 12),
                  SpPanel(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        const Eyebrow('What to do'),
                        const SizedBox(height: 8),
                        Text(
                          _job.description!,
                          style: const TextStyle(fontSize: 15, height: 1.45),
                        ),
                      ],
                    ),
                  ),
                ],

                const SizedBox(height: 12),

                if (claim != null)
                  _ClaimSummary(
                    claim: claim,
                    onOpen: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (BuildContext context) => ClaimOutcomeScreen(claim: claim),
                      ),
                    ),
                  )
                else if (queued)
                  SpPanel(
                    accent: SpColors.partial,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        const Eyebrow('Recorded on this phone', color: SpColors.partial),
                        const SizedBox(height: 6),
                        const Text(
                          'This job is marked complete and is waiting for signal. '
                          'You do not need to do it again.',
                          style: TextStyle(fontSize: 15, height: 1.45),
                        ),
                        const SizedBox(height: 12),
                        OutlinedButton(
                          onPressed:
                              widget.outbox.flushing ? null : () => widget.outbox.flush(),
                          child: Text(
                            widget.outbox.flushing ? 'Sending…' : 'Try sending now',
                          ),
                        ),
                      ],
                    ),
                  )
                else if (!_job.acceptsClaim)
                  SpPanel(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        const Eyebrow('Closed'),
                        const SizedBox(height: 6),
                        Text(
                          'This job is ${_job.status.toLowerCase().replaceAll("_", " ")} '
                          'and no longer takes a completion claim.',
                          style: const TextStyle(fontSize: 15, height: 1.45),
                        ),
                      ],
                    ),
                  ),

                if (canSubmit) ...<Widget>[
                  ConsentNotice(deviceReference: _job.deviceReference),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: () => _submit(),
                    child: const Text('Mark this job complete'),
                  ),
                ],
              ],
            );
          },
        ),
      ),
    );
  }

  Future<void> _submit() async {
    final claim = await Navigator.of(context).push<Claim?>(
      MaterialPageRoute<Claim?>(
        builder: (BuildContext context) => SubmitClaimScreen(
          job: _job,
          outbox: widget.outbox,
        ),
      ),
    );

    if (!mounted) return;

    await _reload();

    if (claim != null && mounted) {
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (BuildContext context) => ClaimOutcomeScreen(claim: claim),
        ),
      );
    }
  }
}

class _ClaimSummary extends StatelessWidget {
  const _ClaimSummary({required this.claim, required this.onOpen});

  final Claim claim;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final state = claim.decisionState;

    return InkWell(
      onTap: onOpen,
      borderRadius: BorderRadius.circular(3),
      child: SpPanel(
        accent: state != null ? SpColors.forState(state) : SpColors.signal,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Eyebrow('Your claim'),
            const SizedBox(height: 8),
            Row(
              children: <Widget>[
                Text(claim.reference, style: kMono.copyWith(color: SpColors.ink)),
                const Spacer(),
                if (state != null)
                  StateBadge(state)
                else
                  const Eyebrow('Being checked', color: SpColors.signal),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              state?.explanation ??
                  'Submitted. Operations will run the network check shortly.',
              style: const TextStyle(fontSize: 14, color: SpColors.ink2, height: 1.45),
            ),
          ],
        ),
      ),
    );
  }
}
