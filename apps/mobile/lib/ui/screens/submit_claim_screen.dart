import 'package:flutter/material.dart';

import '../../data/pending_claim.dart';
import '../../models/models.dart';
import '../../state/outbox_controller.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/consent_notice.dart';

/// Marking a job complete.
///
/// The claim is an assertion and nothing more. There is no field here for
/// evidence, because a client cannot supply evidence about itself — that is
/// the whole point of the product.
class SubmitClaimScreen extends StatefulWidget {
  const SubmitClaimScreen({super.key, required this.job, required this.outbox});

  final WorkOrder job;
  final OutboxController outbox;

  @override
  State<SubmitClaimScreen> createState() => _SubmitClaimScreenState();
}

class _SubmitClaimScreenState extends State<SubmitClaimScreen> {
  final TextEditingController _notes = TextEditingController();

  String _claimType = 'SERVICE_COMPLETED';
  bool _sending = false;

  static const Map<String, String> _types = <String, String>{
    'SERVICE_COMPLETED': 'Work completed',
    'SERVICE_ATTEMPTED': 'Attended, could not complete',
    'SITE_VISIT': 'Site visit only',
    'EQUIPMENT_REPLACED': 'Equipment replaced',
  };

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => _sending = true);

    final pending = PendingClaim.create(
      workOrderId: widget.job.id,
      workOrderReference: widget.job.reference,
      // Captured on the handset: an hour spent queued underground must not
      // move the time the work was actually finished.
      claimedAt: DateTime.now(),
      notes: _notes.text,
      claimType: _claimType,
    );

    Claim? claim;

    try {
      claim = await widget.outbox.enqueue(pending);
    } finally {
      if (mounted) setState(() => _sending = false);
    }

    if (!mounted) return;

    if (claim == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Saved on this phone. It will send when you have signal.'),
        ),
      );
    }

    Navigator.of(context).pop(claim);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Complete ${widget.job.reference}')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
          children: <Widget>[
            Text(
              widget.job.site.name ?? '—',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 4),
            Text(
              widget.job.customer ?? '—',
              style: const TextStyle(fontSize: 15, color: SpColors.ink2),
            ),
            const SizedBox(height: 20),

            SpPanel(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Eyebrow('What happened'),
                  const SizedBox(height: 10),
                  for (final MapEntry<String, String> entry in _types.entries)
                    RadioListTile<String>(
                      value: entry.key,
                      groupValue: _claimType,
                      onChanged: _sending
                          ? null
                          : (String? value) =>
                              setState(() => _claimType = value ?? _claimType),
                      title: Text(entry.value, style: const TextStyle(fontSize: 15)),
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                    ),
                ],
              ),
            ),

            const SizedBox(height: 12),

            SpPanel(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Eyebrow('Anything worth noting'),
                  const SizedBox(height: 10),
                  TextField(
                    controller: _notes,
                    enabled: !_sending,
                    maxLines: 4,
                    maxLength: 2000,
                    decoration: const InputDecoration(
                      hintText: 'Optional. What did you find, what did you do?',
                    ),
                  ),
                  const Text(
                    'Notes go to operations as your account of the job. They are read '
                    'as your words, and they never change what the network reports.',
                    style: TextStyle(fontSize: 13, color: SpColors.ink3, height: 1.4),
                  ),
                ],
              ),
            ),

            const SizedBox(height: 12),
            ConsentNotice(deviceReference: widget.job.deviceReference, compact: true),
            const SizedBox(height: 20),

            FilledButton(
              onPressed: _sending ? null : _send,
              child: Text(_sending ? 'Submitting…' : 'Submit claim'),
            ),
            const SizedBox(height: 10),
            const Text(
              'Works without signal. Your claim is saved here and sent automatically.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 13, color: SpColors.ink3),
            ),
          ],
        ),
      ),
    );
  }
}
