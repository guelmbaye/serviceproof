import 'package:flutter/material.dart';

import '../../state/outbox_controller.dart';
import '../theme.dart';
import 'common.dart';

/// Shown whenever work is sitting on the handset.
///
/// The wording matters: the claim is *recorded*, not lost, and the banner
/// says so. A technician who thinks their work vanished will redo it.
class OutboxBanner extends StatelessWidget {
  const OutboxBanner({super.key, required this.outbox});

  final OutboxController outbox;

  @override
  Widget build(BuildContext context) {
    if (outbox.count == 0) return const SizedBox.shrink();

    final plural = outbox.count == 1 ? 'claim' : 'claims';

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: SpPanel(
        accent: SpColors.partial,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Eyebrow('Waiting to send', color: SpColors.partial),
            const SizedBox(height: 6),
            Text(
              '${outbox.count} $plural recorded on this phone',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 4),
            const Text(
              'Your work is saved. It will send itself as soon as there is signal, '
              'and sending twice cannot create a duplicate.',
              style: TextStyle(fontSize: 14, color: SpColors.ink2, height: 1.4),
            ),
            const SizedBox(height: 12),
            Row(
              children: <Widget>[
                Expanded(
                  child: OutlinedButton(
                    onPressed: outbox.flushing ? null : () => outbox.flush(),
                    child: Text(outbox.flushing ? 'Sending…' : 'Try sending now'),
                  ),
                ),
              ],
            ),
            if (outbox.pending.any((claim) => claim.lastError != null)) ...<Widget>[
              const SizedBox(height: 10),
              for (final claim
                  in outbox.pending.where((claim) => claim.lastError != null))
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    '${claim.workOrderReference} · ${claim.lastError}',
                    style: kMono.copyWith(fontSize: 12),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
