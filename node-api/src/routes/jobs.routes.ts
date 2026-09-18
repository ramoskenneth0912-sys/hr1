import { Router } from 'express';
import { index, show } from '../controllers/jobs.controller.js';

export const jobsRouter = Router();

jobsRouter.get('/', index);

// PHP router parity: index.php sets $id1 = null unless the segment is all
// digits (ctype_digit); a non-numeric id falls through to the list handler.
jobsRouter.get('/:id', (req, res) => {
  if (!/^\d+$/.test(req.params.id)) {
    return index(req, res);
  }
  return show(req, res);
});

export default jobsRouter;
